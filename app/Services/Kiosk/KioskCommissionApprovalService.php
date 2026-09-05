<?php

namespace App\Services\Kiosk;

use App\Models\Mysuncash\Customer;
use App\Models\Mysuncash\CustomerSettlement;
use App\Models\Mysuncash\KioskCommissionTransaction;
use App\Models\Mysuncash\KioskManager;
use App\Models\User;
use App\Services\Transactions\Support\LedgerAdjuster;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Kiosk > Commission Approval" (legacy `fastpay::kiosk_commission_approval()`
 * / `commission_approval_filter()` / `kiosk_commission_history()` /
 * `kiosk_commission_approval_form()` / `approve_commission()` /
 * `reject_commission()`, all in `kiosk_model.php`). A review queue for
 * terminal commission payouts an out-of-band process (not present in this
 * codebase) inserts into `kiosk_commission_transactions` with `status =
 * 'pending'` — staff approve (disburse) or reject each one here.
 *
 * The recipient is either the terminal's `kiosk_managers` row, or an
 * override "commission partner" customer when `kiosk_terminal
 * .commission_user_account` is set (matched against `customers.mobile` or
 * `customers.suncash_bank_account`). Approving either credits the
 * recipient's SunCash wallet (`ezkard_accounts.card_balance`, via the same
 * `LedgerAdjuster` every other money-movement feature in this app already
 * uses) or, for a manager configured for bank payout, creates a
 * `customer_settlements` row that lands in the existing "Customer
 * Settlements" approval queue (`CustomerSettlementService`) for the actual
 * wire transfer — that queue already special-cases the `KioskCommission`
 * channel (see its `NO_REFUND_CHANNELS`), confirming this is the same table
 * both features share.
 *
 * Three deliberate deviations from legacy, each closing a real gap rather
 * than replicating it:
 *
 * 1. Legacy creates the `customer_settlements` "INITIAL" row as a
 *    SIDE EFFECT of merely loading the Approve/Reject FORM (a GET request)
 *    — clicking "Approve", viewing the form, then navigating away without
 *    submitting leaves an orphaned row behind forever. This port creates
 *    that row only inside the actual approve write, and skips creating it
 *    at all on reject (legacy's own reject just marks that view-created row
 *    `DELETED` — a dead end either way, so simply never creating it reaches
 *    the identical practical end state without the vestigial row).
 * 2. Legacy's approve/reject POST handlers trust `commission_rate`/
 *    `commission_type`/`commission_payment` values ECHOED BACK from hidden
 *    form fields the client could tamper with. This port recomputes them
 *    server-side from the live transaction + terminal data instead of
 *    accepting them from the request at all.
 * 3. Legacy's approve validates the recipient customer's identity/active
 *    status only for the wallet-credit path, never for bank-deposit payouts
 *    (trusts `kiosk_manager_details` unconditionally). Not hardened here —
 *    replicated as-is, since bank-deposit payouts still land in the
 *    existing Customer Settlements queue for a second human review before
 *    any money actually moves.
 */
class KioskCommissionApprovalService
{
    public const STATUS_OPTIONS = [
        'pending' => 'Pending',
        'processed' => 'Approved',
        'rejected' => 'Rejected',
    ];

    public function __construct(private readonly LedgerAdjuster $ledger) {}

    private function commissionTypeLabel(?int $commissionType): string
    {
        return match ($commissionType) {
            1 => 'Fixed',
            2 => 'Percentage',
            3 => 'Greater Amount',
            4 => 'Fixed + Percentage',
            default => 'No Commission Type Settings',
        };
    }

    /** Legacy's `commission_rate` CASE expression — a display string, not a number. */
    private function commissionRateDisplay(?int $commissionType, float $fixedValue, float $agentCommission, float $totalRevenue): string
    {
        $percent = $totalRevenue != 0.0 ? round(($agentCommission / $totalRevenue) * 100, 2) : 0.0;

        return match ($commissionType) {
            1 => '$'.$fixedValue,
            2 => "{$percent}%",
            3 => '$'.$fixedValue.' or '.$percent.'%',
            4 => '$'.round($percent + $fixedValue, 2),
            default => 'No commission rate',
        };
    }

    /** Legacy: `IF(commission_type = 3 AND fixed > agent_commission, fixed, agent_commission)`. */
    private function commissionPayment(?int $commissionType, float $fixedValue, float $agentCommission): float
    {
        return ($commissionType === 3 && $fixedValue > $agentCommission) ? $fixedValue : $agentCommission;
    }

    public function listLocations(): array
    {
        return DB::connection('mysuncash')->table('kiosk_terminal')
            ->whereNotNull('location')->where('location', '!=', '')
            ->distinct()->orderBy('location')->pluck('location')->all();
    }

    /**
     * Legacy `get_kiosk_commission_approval()`. `$year`/`$month` scope the
     * whole calendar month (legacy's `by_month` branch, the one the "Apply
     * Filters" button actually uses); `$status`/`$location`/`$partnerName`
     * are optional narrower filters.
     */
    public function list(int $year, string $month, ?string $status, ?string $location, ?string $partnerName): array
    {
        $query = DB::connection('mysuncash')->table('kiosk_commission_transactions as kct')
            ->join('kiosk_terminal as kt', 'kt.id', '=', 'kct.terminal_id')
            ->leftJoin('kiosk_managers as km', function ($join) {
                $join->on('km.id', '=', 'kt.manager_id')->where('kt.manager_id', '!=', '');
            })
            ->leftJoin('island as i', 'i.id', '=', 'kt.island')
            ->leftJoin('customers as c', function ($join) {
                $join->on('c.mobile', '=', 'kt.commission_user_account')
                    ->orOn('c.suncash_bank_account', '=', 'kt.commission_user_account');
            })
            ->whereRaw('YEAR(kct.create_date) = ?', [$year])
            ->where('kct.month_report', $month);

        if ($status) {
            $query->where('kct.status', $status);
        }
        if ($location) {
            $query->where('kt.location', 'like', "%{$location}%");
        }
        if ($partnerName) {
            $query->where(function ($q) use ($partnerName) {
                $q->where(DB::raw("CONCAT(c.first_name, ' ', c.last_name)"), 'like', "%{$partnerName}%")
                    ->orWhere(DB::raw("CONCAT(km.manager_firstname, ' ', km.manager_lastname)"), 'like', "%{$partnerName}%");
            });
        }

        $rows = $query->orderByDesc('kct.create_date')
            ->get([
                'kct.transaction_id', 'kct.terminal_id', 'kct.status', 'kct.create_date', 'kct.total_amount', 'kct.total_revenue', 'kct.agent_commission',
                'kt.name as kiosk', 'kt.location', 'kt.commission_type', 'kt.commission_fixed_value', 'kt.commission_user_account',
                'i.name as island', 'km.mobile as manager_mobile',
                DB::raw("CONCAT(km.manager_firstname, ' ', km.manager_lastname) as manager_name"),
                DB::raw("CONCAT(c.first_name, ' ', c.last_name) as customer_name"),
            ]);

        $result = [];
        foreach ($rows as $row) {
            $hasOverride = filled($row->commission_user_account);
            $fixedValue = (float) $row->commission_fixed_value;
            $agentCommission = (float) $row->agent_commission;
            $commissionType = $row->commission_type !== null ? (int) $row->commission_type : null;

            $result[] = [
                'transaction_id' => $row->transaction_id,
                'terminal_id' => (int) $row->terminal_id,
                'kiosk' => $row->kiosk,
                'location' => $row->location,
                'island' => $row->island,
                'partner_name' => $hasOverride ? trim((string) $row->customer_name) : trim((string) $row->manager_name),
                'partner_mobile' => $hasOverride ? $row->commission_user_account : $row->manager_mobile,
                'total_amount' => (float) $row->total_amount,
                'total_revenue' => (float) $row->total_revenue,
                'commission_type' => $this->commissionTypeLabel($commissionType),
                'commission_rate' => $this->commissionRateDisplay($commissionType, $fixedValue, $agentCommission, (float) $row->total_revenue),
                'commission_payment' => round($this->commissionPayment($commissionType, $fixedValue, $agentCommission), 2),
                'status' => $row->status,
                'create_date' => $row->create_date,
            ];
        }

        $totals = ['total_volume' => 0.0, 'total_revenue' => 0.0, 'total_commission_payments' => 0.0];
        foreach ($result as $row) {
            $totals['total_volume'] += $row['total_amount'];
            $totals['total_revenue'] += $row['total_revenue'];
            $totals['total_commission_payments'] += $row['commission_payment'];
        }
        $totals = array_map(fn ($v) => round($v, 2), $totals);

        return ['rows' => $result, 'totals' => $totals];
    }

    /** Legacy `get_kiosk_commission_histories()` — last 10 rows for a terminal, any status. */
    public function history(int $terminalId): array
    {
        $rows = DB::connection('mysuncash')->table('kiosk_commission_transactions as kct')
            ->join('kiosk_terminal as kt', 'kt.id', '=', 'kct.terminal_id')
            ->where('kct.terminal_id', $terminalId)
            ->orderByDesc('kct.id')
            ->limit(10)
            ->get([
                'kct.transaction_id', 'kct.status', 'kct.create_date', 'kct.total_amount', 'kct.total_revenue', 'kct.agent_commission',
                'kt.commission_type', 'kt.commission_fixed_value',
            ]);

        return $rows->map(function ($row) {
            $fixedValue = (float) $row->commission_fixed_value;
            $agentCommission = (float) $row->agent_commission;
            $commissionType = $row->commission_type !== null ? (int) $row->commission_type : null;

            return [
                'transaction_id' => $row->transaction_id,
                'status' => $row->status,
                'create_date' => $row->create_date,
                'total_amount' => (float) $row->total_amount,
                'total_revenue' => (float) $row->total_revenue,
                'commission_type' => $this->commissionTypeLabel($commissionType),
                'commission_rate' => $this->commissionRateDisplay($commissionType, $fixedValue, $agentCommission, (float) $row->total_revenue),
                'commission_payment' => round($this->commissionPayment($commissionType, $fixedValue, $agentCommission), 2),
            ];
        })->all();
    }

    /**
     * @throws ValidationException
     */
    private function findPendingOrFail(string $transactionId): object
    {
        $row = DB::connection('mysuncash')->table('kiosk_commission_transactions')
            ->where('transaction_id', $transactionId)
            ->first();

        if (! $row) {
            throw ValidationException::withMessages(['transaction_id' => ['This commission transaction was not found.']]);
        }

        return $row;
    }

    /**
     * Legacy `get_agent_commission_by_transid()`, minus the settlement-row
     * side effect (see class docblock, deviation 1).
     *
     * @throws ValidationException
     */
    public function show(string $transactionId): array
    {
        $kct = $this->findPendingOrFail($transactionId);
        $terminal = DB::connection('mysuncash')->table('kiosk_terminal')->where('id', $kct->terminal_id)->first();
        if (! $terminal) {
            throw ValidationException::withMessages(['transaction_id' => ['This kiosk terminal was not found.']]);
        }

        $hasOverride = filled($terminal->commission_user_account);
        $manager = $terminal->manager_id ? KioskManager::with('details')->find($terminal->manager_id) : null;
        $overrideCustomer = $hasOverride
            ? Customer::where('mobile', $terminal->commission_user_account)->orWhere('suncash_bank_account', $terminal->commission_user_account)->first()
            : null;

        $fixedValue = (float) $terminal->commission_fixed_value;
        $agentCommission = (float) $kct->agent_commission;
        $liveCommissionType = (int) $terminal->commission_type;
        // Legacy also falls back to the snapshotted processed/rejected type if the live
        // terminal config no longer maps to a known case — replicated for parity.
        $commissionType = in_array($liveCommissionType, [1, 2, 3, 4], true) ? $liveCommissionType
            : (int) ($kct->processed_commission_type ?: $kct->rejected_commission_type ?: 0);

        $details = $manager?->details;
        $paymentType = match (true) {
            $hasOverride => 'suncash',
            blank($details?->payment_type) => 'no setup',
            default => strtolower((string) $details->payment_type),
        };

        $accountNo = $details?->bank_account_number;
        $maskedAccountNo = filled($accountNo) && $accountNo !== '-1'
            ? str_repeat('0', max(0, strlen((string) $accountNo) - 4)).substr((string) $accountNo, -4)
            : 'N/A';

        return [
            'transaction_id' => $kct->transaction_id,
            'status' => $kct->status,
            'kiosk' => $terminal->name,
            'location' => $terminal->location,
            'commission_user_account' => $hasOverride ? $terminal->commission_user_account : $manager?->mobile,
            'partner_name' => $hasOverride
                ? trim((string) $overrideCustomer?->first_name.' '.(string) $overrideCustomer?->last_name)
                : trim((string) $manager?->manager_firstname.' '.(string) $manager?->manager_lastname),
            'partner_mobile' => $hasOverride ? $terminal->commission_user_account : $manager?->mobile,
            'commission_type' => $this->commissionTypeLabel($commissionType),
            'commission_rate' => $this->commissionRateDisplay($commissionType, $fixedValue, $agentCommission, (float) $kct->total_revenue),
            'commission_payment' => round($this->commissionPayment($commissionType, $fixedValue, $agentCommission), 2),
            'payment_type' => match ($paymentType) {
                'bank_deposit', 'bank deposit' => 'Bank Deposit',
                'no setup' => 'No SetUp',
                default => 'SunCash',
            },
            'is_bank_deposit' => in_array($paymentType, ['bank_deposit', 'bank deposit'], true),
            'is_enabled' => $paymentType !== 'no setup',
            'account_name' => filled($details?->bank_account_name) ? $details->bank_account_name : 'N/A',
            'account_no' => $maskedAccountNo,
        ];
    }

    /**
     * Legacy `approve_kiosk_commission()`, with the client-tamper surface
     * closed (see class docblock, deviation 2) and the settlement row
     * created here instead of on form view (deviation 1).
     *
     * @throws ValidationException
     */
    public function approve(string $transactionId, array $data, User $actor): void
    {
        $kct = $this->findPendingOrFail($transactionId);
        if ($kct->status !== KioskCommissionTransaction::STATUS_PENDING) {
            throw ValidationException::withMessages(['status' => ['Transaction has already been processed/rejected.']]);
        }

        $terminal = DB::connection('mysuncash')->table('kiosk_terminal')->where('id', $kct->terminal_id)->first();
        if (! $terminal) {
            throw ValidationException::withMessages(['transaction_id' => ['This kiosk terminal was not found.']]);
        }

        $hasOverride = filled($terminal->commission_user_account);
        $fixedValue = (float) $terminal->commission_fixed_value;
        $agentCommission = (float) $kct->agent_commission;
        $commissionType = (int) $terminal->commission_type;
        $commissionPayment = round($this->commissionPayment($commissionType, $fixedValue, $agentCommission), 2);

        if ($commissionPayment <= 0) {
            throw ValidationException::withMessages(['commission_payment' => ['Invalid commission payment. The amount must be greater than 0.00']]);
        }

        $manager = $terminal->manager_id ? KioskManager::with('details')->find($terminal->manager_id) : null;
        $details = $manager?->details;
        $isBankDeposit = ! $hasOverride && strtolower((string) $details?->payment_type) === 'bank_deposit';

        $overrideCustomer = null;
        if (! $isBankDeposit) {
            $account = $hasOverride ? $terminal->commission_user_account : $manager?->mobile;
            $overrideCustomer = filled($account)
                ? Customer::where('mobile', $account)->orWhere('suncash_bank_account', $account)->first()
                : null;
            if (! $overrideCustomer || $overrideCustomer->status !== 'A') {
                throw ValidationException::withMessages(['commission_user_account' => ['Sorry, your commission user account is not active or not found.']]);
            }
        }

        $accountType = (string) ($data['account_type'] ?? '');
        $depositType = (string) ($data['deposit_type'] ?? '');
        if ($isBankDeposit && ($accountType === '' || $depositType === '')) {
            throw ValidationException::withMessages(['account_type' => ['Invalid account type or deposit type.']]);
        }

        $commissionTypeLabel = $this->commissionTypeLabel($commissionType);
        $commissionRate = $this->commissionRateDisplay($commissionType, $fixedValue, $agentCommission, (float) $kct->total_revenue);
        $actorName = $actor->name ?? $actor->email;

        DB::connection('mysuncash')->transaction(function () use ($kct, $terminal, $details, $isBankDeposit, $overrideCustomer, $accountType, $depositType, $commissionPayment, $commissionTypeLabel, $commissionRate, $actorName) {
            if ($isBankDeposit) {
                CustomerSettlement::create([
                    'customer_id' => $terminal->manager_id,
                    'origin_id' => -1,
                    'transaction_type' => 'DEBIT',
                    'linked_bank_branch_id' => $details?->bank_branch_id,
                    'amount' => $commissionPayment,
                    'total_amount' => $commissionPayment,
                    'fee' => 0,
                    'withdrawal_type' => $depositType,
                    'account_type' => $accountType,
                    'customer_number' => $terminal->manager_id ? KioskManager::find($terminal->manager_id)?->mobile : null,
                    'status' => 'PENDING',
                    'channel' => 'KioskCommission',
                    'transaction_reference_id' => $kct->transaction_id,
                    'created_date' => now(),
                    'created_by' => $actorName,
                    'updated_date' => now(),
                    'updated_by' => $actorName,
                ]);
            } else {
                $ledgerTransaction = $this->ledger->adjustCardBalance(
                    $overrideCustomer->ezkard_account_id,
                    'add',
                    $commissionPayment,
                    121,
                    "Kiosk Commission - Credit ({$kct->transaction_id}) ",
                    $kct->merchant_id,
                    $kct->transaction_id
                );

                $this->ledger->logCustomerHistory(
                    $overrideCustomer,
                    $overrideCustomer->ezkard_account_id,
                    (string) $ledgerTransaction->transaction_id,
                    'Commission',
                    'KIOSK_COMMISSION',
                    "Kiosk Commission - Credit ({$kct->transaction_id}) ",
                    $commissionPayment,
                    'CREDIT'
                );
            }

            DB::connection('mysuncash')->table('kiosk_commission_transactions')
                ->where('transaction_id', $kct->transaction_id)
                ->where('status', KioskCommissionTransaction::STATUS_PENDING)
                ->update([
                    'status' => KioskCommissionTransaction::STATUS_PROCESSED,
                    'updated_by' => $actorName,
                    'approved_date' => now(),
                    'approved_by' => $actorName,
                    'processed_commission_rate' => $commissionRate,
                    'processed_commission_type' => $commissionTypeLabel,
                    'processed_commission_payment' => $commissionPayment,
                ]);
        });
    }

    /**
     * Legacy `reject_commission()` — no settlement-row involvement at all
     * here (see class docblock, deviation 1).
     *
     * @throws ValidationException
     */
    public function reject(string $transactionId, array $data, User $actor): void
    {
        $kct = $this->findPendingOrFail($transactionId);
        if ($kct->status !== KioskCommissionTransaction::STATUS_PENDING) {
            throw ValidationException::withMessages(['status' => ['Transaction has already been processed/rejected.']]);
        }

        $note = trim((string) ($data['reject_note'] ?? ''));
        if (mb_strlen($note) <= 20) {
            throw ValidationException::withMessages(['reject_note' => ['Please provide a rejection note of more than 20 characters.']]);
        }

        $terminal = DB::connection('mysuncash')->table('kiosk_terminal')->where('id', $kct->terminal_id)->first();
        $fixedValue = (float) ($terminal->commission_fixed_value ?? 0);
        $agentCommission = (float) $kct->agent_commission;
        $commissionType = (int) ($terminal->commission_type ?? 0);

        $actorName = $actor->name ?? $actor->email;

        DB::connection('mysuncash')->table('kiosk_commission_transactions')
            ->where('transaction_id', $kct->transaction_id)
            ->where('status', KioskCommissionTransaction::STATUS_PENDING)
            ->update([
                'status' => KioskCommissionTransaction::STATUS_REJECTED,
                'updated_by' => $actorName,
                'rejected_date' => now(),
                'rejected_by' => $actorName,
                'rejected_note' => $note,
                'rejected_commission_rate' => $this->commissionRateDisplay($commissionType, $fixedValue, $agentCommission, (float) $kct->total_revenue),
                'rejected_commission_type' => $this->commissionTypeLabel($commissionType),
                'rejected_commission_payment' => round($this->commissionPayment($commissionType, $fixedValue, $agentCommission), 2),
            ]);
    }
}
