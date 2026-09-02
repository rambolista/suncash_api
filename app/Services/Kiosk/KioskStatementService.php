<?php

namespace App\Services\Kiosk;

use App\Models\Mysuncash\KioskBranch;
use App\Models\Mysuncash\KioskCashMeterTrx;
use App\Models\Mysuncash\KioskMaintenanceDetail;
use App\Models\Mysuncash\KioskMeterUser;
use App\Models\Mysuncash\KioskTerminal;
use App\Models\Mysuncash\WebposTransactionKiosk;
use App\Models\Mysuncash\Ztrail;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Kiosk > Statement" (legacy `fastpay::kiosk_statement()` /
 * `fastpay::kiosk_leger()` — note legacy's own misspelling of "ledger" in
 * the route/method name, not replicated here). A read-only cash report:
 * the main list shows every active terminal's current computed cash
 * balance; "View Details" drills into one terminal's transaction ledger
 * for a date range with a running balance.
 *
 * The ledger merges THREE independent legacy data sources into one
 * timeline — customer transactions (`webpos_transaction_kiosk`), meter
 * clear/replenish events (`kiosk_meters_user`), and recycler/cashbox/
 * reserve maintenance events (`kiosk_maintenance_details`) — exactly as
 * legacy's `kiosk_leger()` does via `array_merge()` + a date sort.
 *
 * Two deliberate simplifications from legacy, both narrow edge cases with
 * no bearing on the vast majority of rows:
 * 1. `kiosk_meters_user`'s rare `is_run=-1 AND total_meter=0.00` case falls
 *    back to re-deriving the amount from raw acceptor/dispenser denomination
 *    JSON via vendor-specific regex key matching — not replicated; that row
 *    simply shows the stored (zero) `total_meter` instead.
 * 2. Legacy's `get_transaction_description()` output and `get_cash_exposure_
 *    reject_v2()` result are both computed but never rendered in the ledger
 *    table (confirmed dead in the view's `<tr>` template) — not ported.
 */
class KioskStatementService
{
    /** Legacy `Fastpay::$transaction_types` (`fastpay.php`), later entries in the same key win on duplicates, exactly as PHP array literal semantics would collapse it. */
    private const TRANSACTION_TYPE_LABELS = [
        'BPL' => 'BPL Bill Pay',
        'WSC' => 'WSC Bill Pay',
        'NPDCo' => 'NPDCO Bill Pay',
        'ALIV' => 'Aliv Bill Pay',
        'BTC-M' => 'BTC Bill Pay',
        'CB' => 'Cable Bahamas Bill Pay',
        'GBPC' => 'GB Power Bill Pay',
        'FIBR' => 'CBL FIBR',
        'BTC_TOPUP' => 'BTC Topup',
        'ALIV_TOPUP' => 'Aliv Topup',
        'KioskTopup' => 'Mobile Topup',
        'INTERNATIONAL_TOPUP' => 'International Topup',
        'GAMINGHOUSE_DEPOSIT' => 'Gaming Deposit',
        'GAMINGHOUSE_WITHDRAWAL' => 'Gaming Withdrawal',
        'LOCAL_GC' => 'Local Giftcard',
        'MGODIGITALSALES' => 'International Gift Cards',
        'CASHOUT' => 'Cashout',
        'LOAD_SANDDOLLAR' => 'Sand Dollar Load',
        'SEND_SANDDOLLAR' => 'Sand Dollar Send',
        'WITHDRAW_SANDDOLLAR' => 'Sand Dollar Withdrawal',
        'GOVERNMENT_PAYMENT' => 'Government Payments',
        'BUSINESS_PAYMENT' => 'Payment code Payments',
        'MONEY_TRANSFER' => 'Domestic Send Money Transfers',
        'BUSINESSPAYMENT' => 'Business Account Payments',
        'BANK_DEPOSIT' => 'Bank Deposit',
        'CONVENIENCE_FEE' => 'Convenience Fee',
        'LOAD_MOBILEWALLET_SANDDOLLAR' => 'Load Mobile Wallet Via Sanddollar',
        'BILLPAY' => 'Billpay',
        'LOAD' => 'Suncash Deposit',
        'SUNCASH_VOUCHER' => 'Suncash Voucher',
        'UNIBUCKS_VOUCHER' => 'Unibucks Voucher',
        'CREDIT_VOUCHER' => 'Credit Voucher',
        'VOUCHER' => 'Voucher',
        'CARD_DEPOSIT' => 'Card Deposit',
        'CARD_WITHDRAWAL' => 'Card Withdrawal',
        'PAYMENT_CODE' => 'Payment Code',
        'BUSINESS_BANK_DEPOSIT' => 'Business Bank Deposit',
        'BUSINESS_ACCOUNT_DEPOSIT' => 'Business Account Deposit',
        'BUSINESS_ACCOUNT_WITHDRAW' => 'Business Account Withdraw',
        'MONEYTRANSFER_WU' => 'Western Union',
        'LOTTO' => 'Lotto',
    ];

    /** Legacy `is_trans_type_debit_credit()` — everything else defaults to CREDIT. */
    private const DEBIT_TRANSACTION_TYPES = [
        'CASHOUT', 'GAMINGHOUSE_WITHDRAWAL', 'WITHDRAW_SANDDOLLAR',
        'CARD_WITHDRAWAL', 'GAMINGHOUSE_WITHDRAW', 'BUSINESS_BANK_WITHDRAWAL',
    ];

    public function listBranches(): array
    {
        return KioskBranch::where('status', KioskBranch::STATUS_ACTIVE)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->all();
    }

    public function listTerminals(?int $branchId = null): array
    {
        $query = KioskTerminal::where('status', KioskTerminal::STATUS_ACTIVE);
        if ($branchId) {
            $query->where('kiosk_branch_id', $branchId);
        }

        return $query->orderBy('name')->get(['id', 'name', 'kiosk_branch_id'])->all();
    }

    private function presentBalance(KioskTerminal $terminal): array
    {
        $reserve = (float) $terminal->cash_total_reserve;
        $cashbox = (float) $terminal->cash_total_cashbox;
        $recycler = (float) $terminal->cash_total_recycler;
        $dispenser = (float) $terminal->terminal_dispenser_balance;
        $acceptor = (float) $terminal->terminal_acceptor_balance;

        $acceptorValue = $cashbox > 0 ? $cashbox : $acceptor;
        $dispenserValue = $recycler > 0 ? $recycler : $dispenser;

        return [
            'id' => $terminal->id,
            'create_date' => $terminal->create_date,
            'machine' => $terminal->name,
            'island_name' => $terminal->islandRecord?->name ?: 'UNASSIGNED',
            'location' => $terminal->location ?: 'UNASSIGNED',
            'balance' => round($dispenserValue + $reserve + $acceptorValue, 2),
        ];
    }

    /**
     * The main Statement list — every active terminal's current computed
     * cash balance, matching legacy's `getKioskTerminalList()`/`statement_filter()`.
     */
    public function balances(?int $branchId = null, ?int $terminalId = null): array
    {
        $query = KioskTerminal::with('islandRecord')->where('status', KioskTerminal::STATUS_ACTIVE);
        if ($branchId) {
            $query->where('kiosk_branch_id', $branchId);
        }
        if ($terminalId) {
            $query->where('id', $terminalId);
        }

        return $query->orderBy('name')
            ->get()
            ->map(fn (KioskTerminal $terminal) => $this->presentBalance($terminal))
            ->all();
    }

    private function transactionTypeLabel(string $type): string
    {
        return self::TRANSACTION_TYPE_LABELS[$type] ?? $type;
    }

    private function isDebit(string $type): bool
    {
        return in_array($type, self::DEBIT_TRANSACTION_TYPES, true);
    }

    /** Legacy `get_ledger_transactions()`. */
    private function customerTransactionRows(int $terminalId, string $dateFrom, string $dateTo): array
    {
        return WebposTransactionKiosk::query()
            ->join('kiosk_terminal', function ($join) {
                $join->on('kiosk_terminal.id', '=', 'webpos_transaction_kiosk.terminal_id')
                    ->where('kiosk_terminal.status', KioskTerminal::STATUS_ACTIVE);
            })
            ->where('webpos_transaction_kiosk.terminal_id', $terminalId)
            ->whereDate('webpos_transaction_kiosk.transaction_date', '>=', $dateFrom)
            ->whereDate('webpos_transaction_kiosk.transaction_date', '<=', $dateTo)
            ->orderByDesc('webpos_transaction_kiosk.id')
            ->select('webpos_transaction_kiosk.*', 'kiosk_terminal.name as machine', 'kiosk_terminal.location as terminal_location')
            ->get()
            ->map(function ($row) {
                $type = (string) $row->transaction_type;
                $isCreditVoucher = $type === 'VOUCHER' && str_starts_with((string) $row->trans_ref_id, '7');
                $financeType = $this->isDebit($type) ? 'DEBIT' : 'CREDIT';

                return [
                    'transaction_date' => $row->transaction_date,
                    'transaction_id' => $row->transaction_id,
                    'machine' => $row->machine,
                    'location' => filled($row->terminal_location) ? strtoupper($row->terminal_location) : 'UNASSIGNED',
                    'transaction_type' => $isCreditVoucher ? 'Credit Voucher' : $this->transactionTypeLabel($type),
                    'amount' => (float) $row->amount,
                    'total_amount' => (float) $row->total_amount,
                    'finance_type' => $financeType,
                ];
            })
            ->all();
    }

    /** Legacy `get_kiosk_clear_or_add_meter()`. */
    private function meterClearOrAddRows(int $terminalId, string $dateFrom, string $dateTo): array
    {
        $rows = KioskMeterUser::query()
            ->join('kiosk_terminal', 'kiosk_terminal.id', '=', 'kiosk_meters_user.terminal_id')
            ->where('kiosk_meters_user.terminal_id', $terminalId)
            ->where('kiosk_meters_user.timestamp', '>=', "{$dateFrom} 00:00:00")
            ->where('kiosk_meters_user.timestamp', '<=', "{$dateTo} 23:59:59")
            ->orderByDesc('kiosk_meters_user.id')
            ->select('kiosk_meters_user.*', 'kiosk_terminal.name as machine', 'kiosk_terminal.location as terminal_location')
            ->get();

        return $rows->map(function ($row) use ($terminalId) {
            $category = strtolower((string) $row->category);
            $isClearAcceptor = str_contains($category, 'print_clear_acceptor');
            $isClearDispenser = str_contains($category, 'print_clear_dispenser');

            $transType = $isClearAcceptor ? 'Clear Acceptor' : ($isClearDispenser ? 'Clear Dispenser' : 'Add Bin / Replenish');
            $financeType = ($isClearAcceptor || $isClearDispenser) ? 'DEBIT' : 'CREDIT';

            $settlementNo = Ztrail::where('kiosk_terminal_id', $terminalId)
                ->whereBetween('timestamp', [
                    date('Y-m-d H:i:s', strtotime($row->timestamp) - 20),
                    date('Y-m-d H:i:s', strtotime($row->timestamp) + 20),
                ])
                ->value('settlement_no');

            $transactionId = filled($settlementNo) ? $settlementNo : 'No Settlement Info';
            $amount = (float) $row->total_meter;

            return [
                'transaction_date' => $row->timestamp,
                'transaction_id' => $transactionId,
                'machine' => $row->machine,
                'location' => filled($row->terminal_location) ? strtoupper($row->terminal_location) : 'UNASSIGNED',
                'transaction_type' => $transType,
                'amount' => $amount,
                'total_amount' => $amount,
                'finance_type' => $financeType,
            ];
        })->all();
    }

    /**
     * Legacy `extract_meter_denom_v2_without_currency()` — sums a
     * meter-snapshot JSON blob's `{Type, Denom, Count}` rows into a
     * `total_cash` per bin (Cashbox/Reject/Recycler/Reserve).
     */
    private function extractMeterDenomTotals(?string $json): array
    {
        $totals = ['Cashbox' => 0.0, 'Reject' => 0.0, 'Recycler' => 0.0, 'Reserve' => 0.0];
        $typeLabels = [1 => 'Recycler', 2 => 'Cashbox', 3 => 'Reject', 0 => 'Reserve'];

        $decoded = json_decode((string) $json, true);
        if (! is_array($decoded)) {
            return $totals;
        }

        foreach ($decoded as $row) {
            $typeName = $typeLabels[$row['Type'] ?? null] ?? null;
            if (! $typeName) {
                continue;
            }
            $denom = (float) ($row['Denom'] ?? 0);
            $count = (int) ($row['Count'] ?? 0);
            if ($denom > 0) {
                $totals[$typeName] += $denom * $count;
            }
        }

        return $totals;
    }

    /** Legacy `get_recycler_replenishments()`. */
    private function maintenanceRows(int $terminalId, string $dateFrom, string $dateTo): array
    {
        $rows = KioskMaintenanceDetail::query()
            ->join('kiosk_terminal', 'kiosk_terminal.id', '=', 'kiosk_maintenance_details.terminal_id')
            ->where('kiosk_maintenance_details.terminal_id', $terminalId)
            ->whereDate('kiosk_maintenance_details.created_date', '>=', $dateFrom)
            ->whereDate('kiosk_maintenance_details.created_date', '<=', $dateTo)
            ->orderByDesc('kiosk_maintenance_details.id')
            ->select('kiosk_maintenance_details.*', 'kiosk_terminal.name as machine', 'kiosk_terminal.location as terminal_location')
            ->get();

        return $rows->map(function ($row) {
            $action = (string) $row->action;
            $type = (string) $row->type;
            $actionLower = strtolower($action);
            $typeLower = strtolower($type);

            $isClear = str_contains($actionLower, 'clear');
            $isAdd = str_contains($typeLower, 'add');
            $financeType = ($isClear && ! $isAdd) ? 'DEBIT' : ($isAdd ? 'CREDIT' : '');

            $totals = $this->extractMeterDenomTotals($row->meter);
            $amount = match (true) {
                str_contains($actionLower, 'reserve') => $totals['Reserve'],
                str_contains($actionLower, 'cashbox') => $totals['Cashbox'],
                str_contains($actionLower, 'recycler') => $totals['Recycler'],
                default => 0.0,
            };

            return [
                'transaction_date' => $row->created_date,
                'transaction_id' => filled($row->settlement_no) ? $row->settlement_no : 'No Settlement Info',
                'machine' => $row->machine,
                'location' => filled($row->terminal_location) ? strtoupper($row->terminal_location) : 'UNASSIGNED',
                'transaction_type' => str_replace('_', ' ', strtoupper($action)),
                'amount' => $amount,
                'total_amount' => $amount,
                'finance_type' => $financeType,
            ];
        })->all();
    }

    /** Legacy `get_customer_total_deposit()`/`get_customer_total_withdrawal()`/`get_total_reserve()` — summed together, matching legacy exactly. */
    private function openingBalance(int $terminalId, string $dateFrom, string $dateTo): float
    {
        $sumFirstOfDay = function (string $type) use ($terminalId, $dateFrom, $dateTo) {
            $from = "{$dateFrom} 00:00:00";
            $to = "{$dateTo} 23:59:59";

            $firstIds = KioskCashMeterTrx::where('terminal_id', $terminalId)
                ->where('type', $type)
                ->whereBetween('timestamp', [$from, $to])
                ->selectRaw('MIN(id) as min_id')
                ->groupBy(DB::raw('DATE(timestamp)'))
                ->pluck('min_id');

            return (float) KioskCashMeterTrx::where('terminal_id', $terminalId)
                ->where('type', $type)
                ->whereBetween('timestamp', [$from, $to])
                ->whereIn('id', $firstIds)
                ->sum('total_meter');
        };

        $deposit = $sumFirstOfDay('in');
        $withdrawal = $sumFirstOfDay('out');
        $reserve = (float) (KioskTerminal::find($terminalId)?->cash_total_reserve ?? 0);

        return $deposit + $withdrawal + $reserve;
    }

    /**
     * @throws ValidationException
     */
    public function ledger(int $terminalId, string $dateFrom, string $dateTo): array
    {
        $terminal = KioskTerminal::where('status', KioskTerminal::STATUS_ACTIVE)->find($terminalId);
        if (! $terminal) {
            throw ValidationException::withMessages(['terminal_id' => ['This kiosk terminal was not found.']]);
        }

        $rows = array_merge(
            $this->customerTransactionRows($terminalId, $dateFrom, $dateTo),
            $this->meterClearOrAddRows($terminalId, $dateFrom, $dateTo),
            $this->maintenanceRows($terminalId, $dateFrom, $dateTo),
        );

        usort($rows, fn ($a, $b) => strtotime((string) $a['transaction_date']) <=> strtotime((string) $b['transaction_date']));

        $openingBalance = $this->openingBalance($terminalId, $dateFrom, $dateTo);
        $balance = $openingBalance;

        $presented = array_map(function ($row) use (&$balance) {
            $displayAmount = $row['finance_type'] === 'DEBIT' ? $row['amount'] : $row['total_amount'];

            if ($row['finance_type'] === 'CREDIT') {
                $balance += $displayAmount;
            } elseif ($row['finance_type'] === 'DEBIT') {
                $balance = max(0, $balance - $displayAmount);
            }

            return [
                'transaction_date' => $row['transaction_date'],
                'transaction_id' => $row['transaction_id'],
                'machine' => $row['machine'],
                'location' => $row['location'],
                'transaction_type' => $row['transaction_type'],
                'total_amount' => round($displayAmount, 2),
                'finance_type' => $row['finance_type'],
                'balance' => round($balance, 2),
            ];
        }, $rows);

        return [
            'terminal' => [
                'id' => $terminal->id,
                'name' => $terminal->name,
                'code' => $terminal->code,
                'location' => $terminal->location,
                'island_name' => $terminal->islandRecord?->name,
            ],
            'opening_balance' => round($openingBalance, 2),
            'rows' => $presented,
        ];
    }
}
