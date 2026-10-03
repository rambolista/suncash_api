<?php

namespace App\Services\Reports;

use App\Services\Reports\Concerns\InclusiveDateRange;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * "Reports > Void" (legacy `voids_reports` controller + model). The "List of
 * Reports" dropdown for this menu (lookup `report_type`, code `void`) has
 * three reports, now tabs: Voided Sales, Voids by Product, Number of Voids —
 * the same columns, filters (From/To, Location, Cashier) and totals as legacy.
 * All read voided (`trans_status_id = 1`) rows of `ezkard_transactions` whose
 * merchant exists; Voided Sales is only the sale type (1), Number of Voids is
 * only types 0, 1, 2 and 20.
 *
 * Deliberate difference from legacy — one row per voided transaction:
 * legacy joined `merchant_terminal_users` on the *merchant*, not on the
 * transaction's cashier, so each voided transaction was repeated once per user
 * of its merchant (187 voided sales came back as 5,256 rows, 778 voids as
 * 11,051) with every one of those users labelled as its "Cashier", and the
 * TOTAL was inflated by the same factor. The ledger row records its own cashier
 * (`merchant_terminal_users_id`), so Cashier, and the Location / Cashier
 * filters, now use that. The set of transactions is otherwise unchanged
 * (merchant must exist and have at least one user, as the old join required);
 * the cashier is blank when the ledger row didn't record one (~2/3 of them).
 *
 * Also: `CAST(timestamp AS DATE) BETWEEN` -> a string range on the (VARCHAR)
 * timestamp (see `tsGuard`), backed by a (trans_status_id, trans_type_id,
 * timestamp) index; Value shows "$" like legacy's first load and PDF (its
 * filtered load switched to " BSD").
 */
class VoidReportService
{
    use InclusiveDateRange;

    public const MODULE_PATH = '/reports/void';

    public const TABS = [
        'voided_sales' => 'Voided Sales Report',
        'voids_by_product' => 'Voids by Product',
        'number_of_voids' => 'Number of Voids',
    ];

    public const COLUMNS = [
        'voided_sales' => [
            ['key' => 'cashier', 'label' => 'Cashier'], ['key' => 'amount', 'label' => 'Value'], ['key' => 'transaction_id', 'label' => 'Transaction ID'],
            ['key' => 'reason', 'label' => 'Reason'], ['key' => 'date', 'label' => 'Date and Time'],
        ],
        'voids_by_product' => [
            ['key' => 'transaction_id', 'label' => 'Transaction ID'], ['key' => 'type', 'label' => 'Transaction Type'], ['key' => 'cashier', 'label' => 'Cashier'],
            ['key' => 'amount', 'label' => 'Value'], ['key' => 'date', 'label' => 'Date and Time'],
        ],
        'number_of_voids' => [['key' => 'type', 'label' => 'Transaction Type'], ['key' => 'count', 'label' => 'Count']],
    ];

    private function db()
    {
        return DB::connection('mysuncash');
    }

    /** Legacy dropdowns: every location in use, and the cashiers (users) as "First Last". */
    public function options(): array
    {
        $users = $this->db()->table('merchant_terminal_users');

        return [
            'locations' => (clone $users)->whereNotNull('location')->distinct()->orderBy('location')->pluck('location')->all(),
            'cashiers' => (clone $users)->selectRaw("DISTINCT CONCAT(first_name, ' ', last_name) as full_name")->whereRaw("CONCAT(first_name, ' ', last_name) IS NOT NULL")->orderBy('full_name')->pluck('full_name')->all(),
        ];
    }

    public function list(string $tab, ?string $from, ?string $to, ?string $location, ?string $cashier): array
    {
        return $tab === 'number_of_voids' ? $this->numberOfVoids($from, $to) : $this->voided($tab === 'voided_sales', $from, $to, $location, $cashier);
    }

    /** Totals strip (and export footer): label => value. */
    public function summary(string $tab, array $rows): array
    {
        if ($tab === 'number_of_voids') {
            return ['Total' => (string) array_sum(array_column($rows, 'count'))];
        }

        return ['Total' => number_format(array_sum(array_map(fn ($r) => (float) ltrim($r['amount'], '$'), $rows)), 2)];
    }

    private function dated(Builder $q, ?string $from, ?string $to): void
    {
        if ($r = $this->range($from, $to)) {
            $this->tsGuard($q);
            $q->where('e.timestamp', '>=', $r[0])->where('e.timestamp', '<=', $r[1]);
        }
    }

    private function voided(bool $salesOnly, ?string $from, ?string $to, ?string $location, ?string $cashier): array
    {
        $q = $this->db()->table('ezkard_transactions as e')
            ->join('clients as c', 'c.id', '=', 'e.merchant_id')
            ->leftJoin('merchant_terminal_users as u', 'u.id', '=', 'e.merchant_terminal_users_id')
            ->whereExists(fn ($s) => $s->selectRaw('1')->from('merchant_terminal_users as m')->whereColumn('m.merchant_id', 'e.merchant_id'))
            ->where('e.trans_status_id', 1)
            ->when($salesOnly, fn ($q) => $q->where('e.trans_type_id', 1))
            ->selectRaw("e.transaction_id, e.description, e.amount, e.`timestamp`, CONCAT(u.first_name, ' ', u.last_name) as full_name")
            ->orderByDesc('e.timestamp')->orderByDesc('e.id');
        $this->dated($q, $from, $to);
        if (filled($location)) {
            $q->where('u.location', $location);
        }
        if (filled($cashier)) {
            $q->whereRaw("CONCAT(u.first_name, ' ', u.last_name) = ?", [$cashier]);
        }

        return $q->get()->map(fn ($r) => $salesOnly
            ? ['cashier' => $r->full_name, 'amount' => '$'.$r->amount, 'transaction_id' => $r->transaction_id, 'reason' => '', 'date' => $r->timestamp]
            : ['transaction_id' => $r->transaction_id, 'type' => $r->description, 'cashier' => $r->full_name, 'amount' => '$'.$r->amount, 'date' => $r->timestamp]
        )->all();
    }

    private function numberOfVoids(?string $from, ?string $to): array
    {
        $q = $this->db()->table('ezkard_transactions as e')
            ->join('clients as c', 'c.id', '=', 'e.merchant_id')
            ->where('e.trans_status_id', 1)
            ->whereIn('e.trans_type_id', [0, 1, 2, 20])
            ->selectRaw('e.description, COUNT(e.trans_type_id) as cnt')
            ->groupBy('e.description')
            ->orderBy('e.description');
        $this->dated($q, $from, $to);

        return $q->get()->map(fn ($r) => ['type' => $r->description, 'count' => (int) $r->cnt])->all();
    }
}
