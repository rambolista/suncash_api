<?php

namespace App\Services\Reports;

use App\Services\Reports\Concerns\InclusiveDateRange;
use Illuminate\Support\Facades\DB;

/**
 * "Reports > Global Sales" (legacy `global_sales_report` controller + model): WebPOS sales (`webpos_transaction`, status 0)
 * summed per merchant, cashier and transaction type for a From/To range (today by default), optionally narrowed to one
 * merchant and/or branch, with a Total Summary under the table. Eleven columns, as in legacy.
 *
 * Kept from legacy: with neither Merchant nor Branch chosen, client 38 is left out; the grouping (clients.merchant_name,
 * cashier name, transaction type — MySQL resolves a bare GROUP BY name to the table column before the SELECT alias, so
 * legacy grouped by clients.merchant_name even though it shows legal_name); a BANK_DEPOSIT with fees included has its fee
 * taken out of the principle amount; the GAMING_HOUSE_* types shown as GAMING_*.
 *
 * Faster, same figures: legacy joined `webpos_transaction` to a copy of itself grouped by id (a derived table over the
 * whole table, rebuilt on every search) just to compute that per-row principle amount; it is now the same CASE written
 * inline. The date window is a range on `transaction_date` (legacy wrapped it in DATE(), which can't use the index).
 * Bug fixed: Start / End Date were MIN / MAX of text like "12/31/2025", which orders by month first, so a range crossing
 * a year (or any month) could show a wrong first / last day; they are the real earliest / latest day of the group.
 * Merchant Name and Branch are shown per group but not grouped on, so where a group spans several (a cashier who sold at
 * more than one branch — 25 of 245 groups on the dev data) legacy showed whichever row MySQL met first; here it is the
 * alphabetically first, so the figures never depend on the query plan.
 */
class GlobalSalesReportService
{
    use InclusiveDateRange;

    public const MODULE_PATH = '/reports/global-sales';

    public const COLUMNS = [
        ['key' => 'merchant_name', 'label' => 'Merchant Name'],
        ['key' => 'cashier_name', 'label' => 'Cashier Name'],
        ['key' => 'branch', 'label' => 'Branch'],
        ['key' => 'transaction_count', 'label' => 'Transaction Count'],
        ['key' => 'amount', 'label' => 'Principle Amount'],
        ['key' => 'transaction_type', 'label' => 'Transaction Type'],
        ['key' => 'fee', 'label' => 'Transaction Fee'],
        ['key' => 'vat', 'label' => 'Vat'],
        ['key' => 'total', 'label' => 'Transaction Total'],
        ['key' => 'start_date', 'label' => 'Start Date'],
        ['key' => 'end_date', 'label' => 'End Date'],
    ];

    private const TYPE_NAMES = ['GAMING_HOUSE_DEPOSIT' => 'GAMING_DEPOSIT', 'GAMING_HOUSE_WITHDRAW' => 'GAMING_WITHDRAW'];

    private const PRINCIPLE = "CASE WHEN w.exclude_fee = 0 AND w.transaction_type = 'BANK_DEPOSIT' THEN w.amount - w.fee_amount ELSE w.amount END";

    private function db()
    {
        return DB::connection('mysuncash');
    }

    // ------------------------------------------------------------------ dropdowns

    /** Legacy `client_list()` — "ClientId-MerchantName" (DBA name for reseller types 5 / 6). */
    public function merchants(): array
    {
        return $this->db()->table('clients')
            ->orderBy('client_id')->orderByDesc('merchant_name')
            ->get(['id', 'client_id', 'merchant_name', 'dba_name', 'reseller_type'])
            ->map(fn ($c) => ['value' => (string) $c->id, 'label' => $c->client_id.'-'.(in_array((int) $c->reseller_type, [5, 6], true) ? $c->dba_name : $c->merchant_name)])
            ->all();
    }

    /** Every branch, or — once a merchant is picked — the branches that merchant's users belong to (legacy `getBranchListByMerchant`). */
    public function branches(?int $merchantId = null): array
    {
        return $this->db()->table('branch as b')
            ->when($merchantId, fn ($q) => $q->whereExists(fn ($s) => $s->selectRaw('1')->from('merchant_terminal_users as m')
                ->whereColumn('m.branch_id', 'b.id')->where('m.merchant_id', (string) $merchantId)))
            ->orderBy('b.description')
            ->get(['b.id', 'b.description'])
            ->map(fn ($b) => ['value' => (string) $b->id, 'label' => $b->description])
            ->all();
    }

    public function options(): array
    {
        return ['merchants' => $this->merchants(), 'branches' => $this->branches()];
    }

    // ------------------------------------------------------------------ report

    /** @param array{from:string,to:string,merchant?:?string,branch?:?string} $f */
    public function list(array $f): array
    {
        [$from, $to] = $this->range($f['from'], $f['to']);
        $merchant = $f['merchant'] ?? null;
        $branch = $f['branch'] ?? null;

        $q = $this->db()->table('webpos_transaction as w')
            ->join('clients as c', 'c.id', '=', 'w.merchant_id')
            ->join('merchant_terminal_users as u', 'u.id', '=', 'w.terminal_user_id')
            ->leftJoin('branch as b', 'b.id', '=', 'w.branch_id')
            ->where('w.status', 0)
            ->whereBetween('w.transaction_date', [$from, $to])
            ->groupBy('c.merchant_name', DB::raw("CONCAT(u.first_name, ' ', u.last_name)"), 'w.transaction_type')
            ->selectRaw('MIN(c.legal_name) AS merchant_name')
            ->selectRaw("CONCAT(u.first_name, ' ', u.last_name) AS cashier_name")
            ->selectRaw('MIN(b.description) AS branch')
            ->selectRaw('COUNT(w.id) AS transaction_count')
            ->selectRaw('SUM('.self::PRINCIPLE.') AS amount')
            ->selectRaw('w.transaction_type')
            ->selectRaw('SUM(w.fee_amount) AS fee, SUM(w.vat_amount) AS vat')
            ->selectRaw('SUM('.self::PRINCIPLE.' + w.fee_amount + w.vat_amount) AS total')
            ->selectRaw('DATE_FORMAT(MIN(w.transaction_date), \'%m/%d/%Y\') AS start_date, DATE_FORMAT(MAX(w.transaction_date), \'%m/%d/%Y\') AS end_date');

        if (filled($merchant)) {
            $q->where('w.merchant_id', $merchant);
        } elseif (blank($branch)) {
            $q->where('c.id', '!=', 38);
        }
        if (filled($branch)) {
            // `branch_id` is a VARCHAR: a string bind keeps its index usable.
            $q->where('w.branch_id', (string) $branch);
        }

        $rows = $q->get();

        $total = ['count' => 0, 'amount' => 0.0, 'fee' => 0.0, 'vat' => 0.0, 'total' => 0.0];
        $data = $rows->map(function ($r) use (&$total) {
            $total['count'] += (int) $r->transaction_count;
            foreach (['amount', 'fee', 'vat', 'total'] as $k) {
                $total[$k] += (float) $r->$k;
            }

            return [
                'merchant_name' => $r->merchant_name,
                'cashier_name' => $r->cashier_name,
                'branch' => $r->branch,
                'transaction_count' => (int) $r->transaction_count,
                'amount' => $this->money($r->amount),
                'transaction_type' => self::TYPE_NAMES[$r->transaction_type] ?? $r->transaction_type,
                'fee' => $this->money($r->fee),
                'vat' => $this->money($r->vat),
                'total' => $this->money($r->total),
                'start_date' => $r->start_date,
                'end_date' => $r->end_date,
            ];
        })->all();

        return [
            'data' => $data,
            'summary' => [
                'Total Transaction Count' => (string) $total['count'],
                'Total Principle Amount' => $this->money($total['amount']),
                'Total Transaction Fees' => $this->money($total['fee']),
                'Total VAT' => $this->money($total['vat']),
                'Grand Total' => $this->money($total['total']),
            ],
        ];
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2);
    }
}
