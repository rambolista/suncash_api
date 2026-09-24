<?php

namespace App\Services\Reports;

use App\Models\Mysuncash\CashoutTransactionV3;
use App\Models\Mysuncash\Merchant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Reports > Money Transfer" (legacy `moneytransfer_reports` controller +
 * `moneytransfer_reports_model`). Legacy's view offered 4 report types via
 * a dropdown, but only 2 — Completed and Pending — ever had a working
 * backend; "Dollar Amount of Pending Payouts" and "Number of Transfers"
 * render empty tables with no controller action or model query behind
 * them anywhere in the codebase, so they're dead UI and aren't ported.
 * Legacy's `cm_island`/`cm_loc` filters are the same story — referenced by
 * the JS but with no matching form fields or model parameters left; only
 * date range and cashier (`initiating_merchant`) are real, working filters.
 *
 * `cashout_transactionsv3` joined to `cashout_transaction_detailsv3` on
 * `cashout_id` had no usable index at all (a full table scan drove the
 * join — see the accompanying migration), so this is real server-side
 * pagination like SMS Logs, not legacy's fetch-300-then-render-client-side
 * (Completed) / fetch-everything-unbounded (Pending) approach.
 */
class MoneyTransferReportService
{
    public const COLUMNS_COMPLETED = [
        ['key' => 'date_requested', 'label' => 'Date Sent'],
        ['key' => 'sender_name', 'label' => 'Originating Account'],
        ['key' => 'sender_location', 'label' => 'Location'],
        ['key' => 'date_processed', 'label' => 'Date Picked Up'],
        ['key' => 'bene_name', 'label' => 'Destination Account'],
        ['key' => 'bene_location', 'label' => 'Location'],
        ['key' => 'cashout_reference', 'label' => 'Transaction #'],
        ['key' => 'amount', 'label' => 'Amount'],
        ['key' => 'fee_amount', 'label' => 'Fees'],
        ['key' => 'cashier', 'label' => 'Cashiers'],
        ['key' => 'source', 'label' => 'Source'],
    ];

    public const COLUMNS_PENDING = [
        ['key' => 'date_requested', 'label' => 'Date Sent'],
        ['key' => 'ticket_status', 'label' => 'Ticket Status'],
        ['key' => 'expiration_date', 'label' => 'Expiry Date'],
        ['key' => 'days_left', 'label' => 'Days Left'],
        ['key' => 'sender_name', 'label' => 'Originating Account'],
        ['key' => 'sender_location', 'label' => 'Location'],
        ['key' => 'bene_name', 'label' => 'Destination Account'],
        ['key' => 'bene_location', 'label' => 'Location'],
        ['key' => 'cashout_reference', 'label' => 'Transaction #'],
        ['key' => 'amount', 'label' => 'Amount'],
        ['key' => 'fee_amount', 'label' => 'Fees'],
        ['key' => 'cashier', 'label' => 'Cashiers'],
        ['key' => 'source', 'label' => 'Source'],
    ];

    private const PER_PAGE = 300;

    private const STATUS_PENDING = 0;

    private const STATUS_COMPLETED = 1;

    /** Legacy's "phone/admin-originated" marker — no branch/store behind the transaction. */
    private const PHONE_MERCHANT_ID = 37;

    private function baseQuery(int $status, ?string $from, ?string $to, ?int $cashierId, ?string $search): Builder
    {
        $query = CashoutTransactionV3::query()
            ->join('cashout_transaction_detailsv3 as d', 'd.cashout_id', '=', 'cashout_transactionsv3.id')
            ->join('clients as c', 'c.id', '=', 'cashout_transactionsv3.initiating_merchant')
            ->where('cashout_transactionsv3.status', $status)
            ->select([
                'cashout_transactionsv3.date_requested', 'cashout_transactionsv3.date_processed',
                'cashout_transactionsv3.cashout_reference', 'cashout_transactionsv3.amount', 'cashout_transactionsv3.currency',
                'cashout_transactionsv3.fee_amount', 'cashout_transactionsv3.initiating_merchant', 'cashout_transactionsv3.island',
                'cashout_transactionsv3.branch_id',
                'd.sender_fname', 'd.sender_lname', 'd.sender_country',
                'd.bene_fname', 'd.bene_lname', 'd.bene_country',
                'c.legal_name',
            ]);

        if ($status === self::STATUS_PENDING) {
            $query->whereNull('cashout_transactionsv3.date_processed');
        }

        // Sargable date-range equivalent of legacy's `CAST(date_requested AS DATE) BETWEEN from AND to`
        // (same inclusive-day semantics, but usable against idxStatusDateRequested).
        if (filled($from)) {
            $query->where('cashout_transactionsv3.date_requested', '>=', Carbon::parse($from)->startOfDay());
        }
        if (filled($to)) {
            $query->where('cashout_transactionsv3.date_requested', '<', Carbon::parse($to)->addDay()->startOfDay());
        }
        if ($cashierId !== null) {
            $query->where('cashout_transactionsv3.initiating_merchant', $cashierId);
        }
        if (filled($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('d.sender_fname', 'like', "%{$search}%")
                    ->orWhere('d.sender_lname', 'like', "%{$search}%")
                    ->orWhere('d.bene_fname', 'like', "%{$search}%")
                    ->orWhere('d.bene_lname', 'like', "%{$search}%")
                    ->orWhere('cashout_transactionsv3.cashout_reference', 'like', "%{$search}%");
            });
        }

        return $query;
    }

    /** Legacy's `source`/`legal_name` CASE expressions — phone/admin-originated transactions show no store and no cashier name. */
    private function isPhoneOriginated(object $row): bool
    {
        return (int) $row->initiating_merchant === self::PHONE_MERCHANT_ID
            && (int) $row->island === -1
            && (int) $row->branch_id === -1;
    }

    private function present(object $row, bool $completed): array
    {
        $isPhone = $this->isPhoneOriginated($row);
        $source = $isPhone ? 'PHONE' : ($completed && blank($row->cashout_reference) ? 'STORE_TO_WALLET' : 'STORE');

        $data = [
            'date_requested' => $row->date_requested,
            'sender_name' => trim($row->sender_fname.' '.$row->sender_lname),
            'sender_location' => $row->sender_country,
            'bene_name' => trim($row->bene_fname.' '.$row->bene_lname),
            'bene_location' => $row->bene_country,
            'cashout_reference' => $row->cashout_reference,
            'amount' => sprintf('%s %s', $row->amount, $row->currency),
            'fee_amount' => number_format((float) $row->fee_amount, 2),
            'cashier' => $isPhone ? '' : $row->legal_name,
            'source' => $source,
        ];

        if ($completed) {
            $data['date_processed'] = $row->date_processed;

            return $data;
        }

        // Legacy: `days_left` is actually days *past* the 90-day pickup window once positive.
        // Whole calendar days only (legacy's DATEDIFF operates on DATE, not DATETIME).
        $overdue = Carbon::parse($row->date_requested)->startOfDay()->diffInDays(now()->startOfDay(), false) - 90;
        $data['expiration_date'] = Carbon::parse($row->date_requested)->addDays(90)->toDateString();
        $data['ticket_status'] = $overdue > 0 ? 'EXPIRED' : 'PENDING';
        $data['days_left'] = $overdue > 0 ? 0 : abs($overdue);

        return $data;
    }

    public function paginatedList(bool $completed, ?string $from, ?string $to, ?int $cashierId, ?string $search, int $page): array
    {
        $paginator = $this->baseQuery($completed ? self::STATUS_COMPLETED : self::STATUS_PENDING, $from, $to, $cashierId, $search)
            ->orderByDesc('cashout_transactionsv3.date_requested')
            ->paginate(self::PER_PAGE, ['*'], 'page', max(1, $page));

        return [
            'data' => $paginator->getCollection()->map(fn ($row) => $this->present($row, $completed))->all(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }

    public function exportRows(bool $completed, ?string $from, ?string $to, ?int $cashierId, ?string $search): array
    {
        return $this->baseQuery($completed ? self::STATUS_COMPLETED : self::STATUS_PENDING, $from, $to, $cashierId, $search)
            ->orderByDesc('cashout_transactionsv3.date_requested')
            ->get()
            ->map(fn ($row) => $this->present($row, $completed))
            ->all();
    }

    /** Legacy `getcashierlist()` — every client, unfiltered (an `initiating_merchant` id can reference any of them). */
    public function cashiers(): array
    {
        return Merchant::orderBy('legal_name')->get(['id', 'legal_name'])->all();
    }
}
