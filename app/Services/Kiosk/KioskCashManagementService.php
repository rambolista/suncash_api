<?php

namespace App\Services\Kiosk;

use App\Models\Mysuncash\KioskBankAccount;
use App\Models\Mysuncash\KioskOtherProof;
use App\Models\Mysuncash\KioskTerminalTransaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Kiosk > Cash Management" (legacy `fastpay::kiosk_cash_management` /
 * `confirm_cash_deposit` / `view_cdep_details` / `delete_cash_deposit`, and
 * `fastpay_kiosk_model::get_cash_mgmt_info` / `kiosk_model::confirm_cash_deposit`
 * / `get_cash_deposit_info` / `view_cdep_details` / `delete_cash_deposit`).
 *
 * The deposit's `in_custody`/`is_secured`/`is_recycled`/`in_store`/
 * `is_verified`/`is_deposited_to_bank` boolean columns are write-only for
 * this screen — legacy's own list view never reads them back; every ✅/❌
 * indicator and next-available-action is derived purely from the
 * `deposit_status` string. They're still written here for downstream/audit
 * parity, just never used to drive this service's own output.
 */
class KioskCashManagementService
{
    public function __construct(private readonly KioskDepositsAdjustmentsService $depositsAdjustments)
    {
    }

    // ── Lookups reused from the Deposits and Adjustments feature ────────────

    public function listStores(): array
    {
        return $this->depositsAdjustments->listStores();
    }

    public function listTerminals(): array
    {
        return $this->depositsAdjustments->listKiosks();
    }

    public function getBanksForBranch(int $branchId): array
    {
        return KioskBankAccount::where('terminal_branch_id', $branchId)
            ->where('user_type', KioskBankAccount::USER_TYPE_TERMINAL)
            ->where('status', KioskBankAccount::STATUS_ACTIVE)
            ->orderByDesc('id')
            ->get()
            ->map(fn (KioskBankAccount $bank) => [
                'id' => $bank->id,
                'label' => trim("{$bank->customer_name} - {$bank->account_number}"),
                'bank_name' => $bank->bank_name,
                'branch_name' => $bank->branch_name,
                'customer_name' => $bank->customer_name,
                'account_number' => $bank->account_number,
            ])
            ->all();
    }

    // ── List ──────────────────────────────────────────────────────────────

    /** Mirrors the PHP switch on deposit_status in administrator/kiosk_cash_management.php (~line 335). */
    private function availableActions(string $status, string $depositLocation): array
    {
        $loc = strtolower($depositLocation);
        $isHeldOrStoreLocation = str_contains($loc, 'held temporary at location') || str_contains($loc, 'deposited suncash store');

        return match ($status) {
            'pending' => array_values(array_filter([
                ['value' => 'in_transit', 'label' => 'Mark as In Transit'],
                $isHeldOrStoreLocation ? ['value' => 'in_treasury', 'label' => 'Held at Treasury'] : null,
                ['value' => 'in_store', 'label' => 'Mark as Held to SunCash Store'],
                ['value' => 'is_recycled', 'label' => 'Mark as Recycled to Kiosk'],
                ['value' => 'is_bank', 'label' => 'Mark as Deposited to Bank'],
                ['value' => 'delete_data', 'label' => 'Delete Transaction'],
            ])),
            'in_transit' => [
                ['value' => 'in_custody', 'label' => 'Mark as In Custody'],
                ['value' => 'in_treasury', 'label' => 'Held at Treasury'],
                ['value' => 'report_issue', 'label' => 'Report Issue'],
            ],
            'in_custody' => [
                ['value' => 'is_secured', 'label' => 'Mark as Secured'],
                ['value' => 'report_discrepancy', 'label' => 'Report Discrepancy'],
            ],
            'in_store' => [
                ['value' => 'in_transit', 'label' => 'Mark as In Transit'],
                ['value' => 'in_treasury', 'label' => 'Held at Treasury'],
            ],
            'in_treasury' => [
                ['value' => 'is_secured', 'label' => 'Mark as Verified & Secured'],
                ['value' => 'report_discrepancy', 'label' => 'Report Discrepancy'],
            ],
            'is_bank' => [
                ['value' => 'is_verified', 'label' => 'Mark as Verified (Audit Trail)'],
            ],
            default => [],
        };
    }

    public function listDeposits(): array
    {
        $dateFrom = now()->subMonths(6)->startOfDay();
        $dateTo = now()->endOfDay();

        $rows = DB::connection('mysuncash')->table('kiosk_terminal_transactions as ktt')
            ->join('kiosk_terminal as kt', 'kt.id', '=', 'ktt.terminal_id')
            ->leftJoin('kiosk_branch as kbr', 'kbr.id', '=', 'kt.kiosk_branch_id')
            ->leftJoin('branch as b', function ($join) {
                $join->on('b.id', '=', 'ktt.deposit_store_id')->where('ktt.deposit_store_id', '!=', '-1');
            })
            ->leftJoin('clients as c', 'c.id', '=', 'b.client_record_id')
            ->where('ktt.trans_type', 'deposit')
            ->whereBetween('ktt.create_date', [$dateFrom, $dateTo])
            ->where(function ($query) {
                $query->where('ktt.recycle_location', 'Location Temporary Holding')
                    ->orWhere('ktt.deposit_location', 'Deposit to Bank')
                    ->orWhere('ktt.deposit_location', 'Held Temporary at Location')
                    ->orWhere('ktt.deposit_location', 'Bank Deposit')
                    ->orWhere('ktt.deposit_location', 'Recycled to Same Kiosk')
                    ->orWhere('ktt.deposit_location', 'Recycled to Another Kiosk');
            })
            ->orderByDesc('ktt.id')
            ->limit(5000)
            ->select([
                'ktt.id as ref_id',
                'ktt.terminal_id',
                'kt.name as main_terminal',
                'kt.location as main_terminal_location',
                DB::raw("IF(c.dba_name = '' OR c.dba_name IS NULL, c.legal_name, c.dba_name) as store_name"),
                'ktt.amount as total_amount',
                'ktt.recyclable_amount',
                'ktt.non_recyclable_amount',
                'ktt.description',
                'ktt.deposit_location',
                'ktt.transaction_id',
                'ktt.deposit_status',
                'ktt.location_at',
                'ktt.create_date as initial_date',
                'ktt.updated_date',
                'ktt.notes',
                'ktt.deposit_note',
                DB::raw("IF(ktt.deposit_status = 'pending', ktt.create_by, ktt.updated_by) as last_assignee"),
                'ktt.held_since',
                'ktt.report_as_discrepancy',
                'ktt.report_as_issue',
                'kbr.id as terminal_branch_id',
                'ktt.deposit_store_id as store_id',
            ])
            ->get();

        $today = now()->toDateString();
        $deposits = [];

        foreach ($rows as $row) {
            $status = strtolower((string) $row->deposit_status);
            $isViewOnly = in_array($status, ['is_secured', 'is_verified', 'is_recycled'], true);

            $completedToday = Carbon::parse($row->initial_date)->toDateString() === $today
                || (filled($row->updated_date) && $row->updated_date !== '0000-00-00 00:00:00' && Carbon::parse($row->updated_date)->toDateString() === $today);

            if ($isViewOnly && ! $completedToday) {
                continue;
            }

            $description = (string) $row->description;
            if ($status === 'in_custody') {
                $target = (filled($row->location_at) && ! filled($row->store_name)) ? $row->location_at : $row->store_name;
                $description = "Delivered to {$target}";
            }
            if ((int) $row->report_as_issue === 1) {
                $description .= ' (Report Issue)';
            } elseif ((int) $row->report_as_discrepancy === 1) {
                $description .= ' (Report Discrepancy)';
            }

            $notes = $row->deposit_note;
            if ($status === 'pending') {
                $notes = $row->notes;
            }

            $inCustodyCheck = in_array($status, ['in_custody', 'is_bank', 'in_store', 'in_treasury'], true)
                || ($isViewOnly && $completedToday);
            $isSecuredCheck = $isViewOnly && $completedToday;

            $heldAmount = match (true) {
                in_array($status, ['in_custody', 'is_bank', 'in_store', 'in_treasury'], true) => (float) $row->total_amount,
                $isViewOnly && $completedToday => (float) $row->total_amount,
                default => 0.0,
            };

            $deposits[] = [
                'id' => (int) $row->ref_id,
                'terminal_id' => (int) $row->terminal_id,
                'date_added' => $row->initial_date,
                'kiosk_id' => $row->main_terminal,
                'kiosk_location' => $row->main_terminal_location ?: 'UNASSIGNED',
                'total_withdrawn' => (float) $row->total_amount,
                'recycled' => (float) $row->recyclable_amount,
                'not_recycled' => (float) $row->non_recyclable_amount,
                'deposit_status_label' => $description,
                'in_custody' => $inCustodyCheck,
                'secured' => $isSecuredCheck,
                'held' => $heldAmount,
                'held_since' => $row->held_since,
                'last_updated_by' => $row->last_assignee,
                'notes' => $notes,
                'deposit_status' => $status,
                'deposit_location' => $row->deposit_location,
                'terminal_branch_id' => $row->terminal_branch_id,
                'store_id' => $row->store_id,
                'is_view_only' => $isViewOnly,
                'available_actions' => $isViewOnly ? [] : $this->availableActions($status, (string) $row->deposit_location),
                'held_bucket' => in_array($status, ['pending', 'in_transit'], true),
                'custody_bucket' => in_array($status, ['in_custody', 'is_bank', 'in_store', 'in_treasury'], true) || ($isViewOnly && $completedToday),
                'secured_bucket' => $isViewOnly && $completedToday,
            ];
        }

        return $deposits;
    }

    /**
     * @throws ValidationException
     */
    public function viewDetails(int $id): array
    {
        $row = DB::connection('mysuncash')->table('kiosk_terminal_transactions as ktt')
            ->join('kiosk_terminal as kt', 'kt.id', '=', 'ktt.terminal_id')
            ->leftJoin('branch as b', function ($join) {
                $join->on('b.id', '=', 'ktt.deposit_store_id')->where('ktt.deposit_store_id', '!=', '-1');
            })
            ->leftJoin('clients as c', 'c.id', '=', 'b.client_record_id')
            ->where('ktt.trans_type', 'deposit')
            ->where('ktt.id', $id)
            ->where(function ($query) {
                $query->where('ktt.recycle_location', 'Location Temporary Holding')
                    ->orWhere('ktt.deposit_location', 'Deposit to Bank')
                    ->orWhere('ktt.deposit_location', 'Held Temporary at Location')
                    ->orWhere('ktt.deposit_location', 'Bank Deposit')
                    ->orWhere('ktt.deposit_location', 'Recycled to Same Kiosk')
                    ->orWhere('ktt.deposit_location', 'Recycled to Another Kiosk');
            })
            ->select([
                'ktt.id as ref_id', 'ktt.transaction_id', 'ktt.location_at',
                DB::raw("IF(c.dba_name = '' OR c.dba_name IS NULL, c.legal_name, c.dba_name) as store_name"),
                'ktt.bank_account', 'ktt.account_name', 'ktt.account_no', 'ktt.bank_branch',
                'ktt.deposit_receipt', 'ktt.deposit_note',
            ])
            ->first();

        if (! $row) {
            throw ValidationException::withMessages(['id' => ['Deposit not found.']]);
        }

        $accountNo = (string) ($row->account_no ?? '');
        $maskedAccount = ($accountNo !== '' && $accountNo !== '-1')
            ? str_repeat('0', max(strlen($accountNo) - 4, 0)).substr($accountNo, -4)
            : '';

        return [
            'transaction_id' => $row->transaction_id,
            'location' => filled($row->location_at) ? $row->location_at : $row->store_name,
            'store_name' => $row->store_name,
            'receipt_url' => $row->deposit_receipt,
            'bank_name' => $row->bank_account ?: '',
            'bank_branch' => $row->bank_branch ?: '',
            'account_name' => $row->account_name ?: '',
            'account_no_masked' => $maskedAccount,
            'deposit_note' => $row->deposit_note,
        ];
    }

    /**
     * Mirrors `kiosk_model::confirm_cash_deposit()` field-by-field.
     *
     * @throws ValidationException
     */
    public function confirmDeposit(int $id, array $data, User $actor): array
    {
        $deposit = KioskTerminalTransaction::find($id);
        if (! $deposit) {
            throw ValidationException::withMessages(['id' => ['Deposit not found.']]);
        }

        $action = (string) ($data['action'] ?? '');
        if (! filled($action)) {
            throw ValidationException::withMessages(['action' => ['Please select a valid action.']]);
        }
        if (strtolower((string) $deposit->deposit_status) === $action) {
            throw ValidationException::withMessages(['action' => ['This transaction is already in that state.']]);
        }

        $notesRequired = in_array($action, ['in_transit', 'is_bank', 'is_recycled'], true);
        $receiptRequired = in_array($action, ['is_bank', 'is_recycled'], true);
        if ($notesRequired && ! filled($data['notes'] ?? null)) {
            throw ValidationException::withMessages(['notes' => ['Please enter a note.']]);
        }
        if ($receiptRequired && ! filled($data['receipt_path'] ?? null)) {
            throw ValidationException::withMessages(['receipt_path' => ['Please upload a receipt.']]);
        }
        if ($action === 'is_bank' && (! filled($data['bank_name'] ?? null) || ! filled($data['bank_branch'] ?? null) || ! filled($data['account_no'] ?? null) || ! filled($data['account_name'] ?? null))) {
            throw ValidationException::withMessages(['bank_name' => ['Please enter valid bank details.']]);
        }

        $actorName = $actor->name ?? $actor->email;
        $now = now();

        $update = [
            'deposit_status' => $action,
            'updated_date' => $now,
            'updated_by' => $actorName,
            'in_custody' => 0,
            'is_secured' => 0,
            'is_recycled' => 0,
            'in_store' => 0,
            'is_verified' => 0,
            'is_deposited_to_bank' => 0,
            'held_since' => $now,
            'description' => $data['description'] ?? $deposit->description,
        ];

        if (filled($data['notes'] ?? null)) {
            $update['deposit_note'] = $data['notes'];
        }
        if (filled($data['receipt_path'] ?? null)) {
            $update['deposit_receipt'] = $data['receipt_path'];
        }

        if ($action === 'in_custody') {
            $update['in_custody'] = 1;
        }

        if ($action === 'is_secured') {
            unset($update['is_secured'], $update['in_custody']);
            $update['is_secured'] = 1;
        }

        if ($action === 'is_recycled') {
            if (filled($data['deposit_terminal_id'] ?? null)) {
                $update['deposit_terminal_id'] = $data['deposit_terminal_id'];
            }
            unset($update['is_recycled'], $update['is_secured'], $update['in_custody']);
        }

        if ($action === 'is_bank') {
            unset($update['is_deposited_to_bank']);
        }

        if (filled($data['bank_name'] ?? null)) {
            $update['bank_account'] = $data['bank_name'];
        }
        if (filled($data['bank_branch'] ?? null)) {
            $update['bank_branch'] = $data['bank_branch'];
        }
        if (filled($data['account_name'] ?? null)) {
            $update['account_name'] = $data['account_name'];
        }
        if (filled($data['account_no'] ?? null)) {
            $update['account_no'] = $data['account_no'];
        }

        if ($action === 'in_store') {
            $update['in_store'] = 1;
            if (filled($data['store_id'] ?? null)) {
                $update['deposit_store_id'] = $data['store_id'];
            }
            unset($update['in_custody'], $update['is_secured']);
        }

        if ($action === 'is_verified') {
            $update['is_verified'] = 1;
            unset($update['is_deposited_to_bank'], $update['in_store'], $update['is_secured']);
        }

        $isReport = in_array($action, ['report_issue', 'report_discrepancy'], true);
        if ($isReport) {
            unset(
                $update['is_deposited_to_bank'], $update['in_store'], $update['is_secured'],
                $update['in_custody'], $update['is_recycled'], $update['is_verified'],
                $update['deposit_status'], $update['description'],
            );
            $update[$action === 'report_discrepancy' ? 'report_as_discrepancy' : 'report_as_issue'] = 1;
        }

        $deposit->update($update);

        KioskOtherProof::create([
            'ref_id' => (string) $id,
            'deposit_status' => $action,
            'store_admin' => $actorName,
            'admin_user' => $actorName,
            'report_receipt' => $isReport ? ($data['receipt_path'] ?? null) : null,
            'other_receipt' => ! $isReport ? ($data['receipt_path'] ?? null) : null,
        ]);

        return ['success' => true, 'message' => 'Transaction has been confirmed.'];
    }

    /**
     * Mirrors `fastpay::delete_cash_deposit()` + `kiosk_model::delete_cash_deposit()`.
     *
     * @throws ValidationException
     */
    public function deleteDeposit(int $id, array $data, User $actor): array
    {
        $deposit = KioskTerminalTransaction::find($id);
        if (! $deposit) {
            throw ValidationException::withMessages(['id' => ['Deposit not found.']]);
        }

        $update = [
            'updated_date' => now(),
            'updated_by' => $actor->name ?? $actor->email,
            'terminal_id' => -1 * abs((int) $deposit->terminal_id),
            'deposit_status' => 'deleted',
        ];

        if (filled($data['note'] ?? null)) {
            $update['notes'] = $data['note'];
        }

        $deposit->update($update);

        return ['success' => true, 'message' => 'Transaction has been deleted.'];
    }
}
