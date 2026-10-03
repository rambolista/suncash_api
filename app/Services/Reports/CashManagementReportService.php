<?php

namespace App\Services\Reports;

use App\Services\Reports\Concerns\InclusiveDateRange;
use Illuminate\Support\Facades\DB;

/**
 * "Reports > Cash Management" (legacy `cashmgnt_reports` controller + model).
 * Its "List of Reports" dropdown (lookup `report_type`, code `cashmgnt`) offers
 * two reports — Sales by Product and Sales by Location — which become tabs.
 * (The page also carries Cash Difference / Total Vat / Net Settlement / Global
 * Sales panels, but the dropdown never offers them and nothing loads them.)
 *
 * Both sum the "Fee - debit" ledger entries (`ezkard_transactions.trans_type_id
 * = 26`), optionally for a From/To day range, as legacy did.
 *
 * Sales by Location: legacy had two different queries. Its first load summed
 * sales per country; "Apply Filters" ran a *different* one that dropped the
 * type-26 filter, didn't SUM (one arbitrary ledger row per country), required a
 * terminal match and labelled the cashier from another column — so a filtered
 * list contradicted the unfiltered one. Here the first-load query is the only
 * one, with the date range added. Its Terminal and Cashier columns are not
 * aggregates (legacy took an arbitrary row per country); they show the
 * location's first matching transaction so the value is at least stable.
 */
class CashManagementReportService
{
    use InclusiveDateRange;

    public const MODULE_PATH = '/reports/cash-management';

    public const TABS = [
        'sales_by_product' => 'Sales by Product',
        'sales_by_location' => 'Sales by Location',
    ];

    public const COLUMNS = [
        'sales_by_product' => [['key' => 'product', 'label' => 'Product'], ['key' => 'sales', 'label' => 'Sales']],
        'sales_by_location' => [
            ['key' => 'location', 'label' => 'Location'], ['key' => 'terminal', 'label' => 'Terminal'],
            ['key' => 'cashier', 'label' => 'Cashier'], ['key' => 'sales', 'label' => 'Sales'],
        ],
    ];

    private function db()
    {
        return DB::connection('mysuncash');
    }

    public function exportColumns(string $tab): array
    {
        return self::COLUMNS[$tab];
    }

    public function list(string $tab, ?string $from, ?string $to): array
    {
        return $tab === 'sales_by_product' ? $this->byProduct($from, $to) : $this->byLocation($from, $to);
    }

    private function money($value): string
    {
        return number_format((float) $value, 2).' BSD';
    }

    private function byProduct(?string $from, ?string $to): array
    {
        $q = $this->db()->table('ezkard_transactions as e')
            ->where('e.trans_type_id', 26)
            ->selectRaw('e.description, SUM(e.amount) as net_sales')
            ->groupBy('e.description')
            ->orderBy('e.description');
        if ($r = $this->range($from, $to)) {
            $this->tsGuard($q);
            $q->where('e.timestamp', '>=', $r[0])->where('e.timestamp', '<=', $r[1]);
        }

        return $q->get()->map(fn ($r) => ['product' => $r->description, 'sales' => $this->money($r->net_sales)])->all();
    }

    private function byLocation(?string $from, ?string $to): array
    {
        $q = $this->db()->table('ezkard_transactions as e')
            ->join('ezkard_accounts as a', 'a.id', '=', 'e.ezkard_id')
            ->join('customers as cu', 'cu.mobile', '=', 'a.mobile_number')
            ->where('e.trans_type_id', 26)
            ->selectRaw('cu.country as location, SUM(e.amount) as amount, MIN(e.id) as first_id')
            ->groupBy('cu.country')
            ->orderBy('cu.country');
        if ($r = $this->range($from, $to)) {
            $this->tsGuard($q);
            $q->where('e.timestamp', '>=', $r[0])->where('e.timestamp', '<=', $r[1]);
        }
        $locations = $q->get();

        $first = $this->db()->table('ezkard_transactions as e')
            ->leftJoin('clients as c', 'c.id', '=', 'e.merchant_id')
            ->whereIn('e.id', $locations->pluck('first_id'))
            ->get(['e.id', 'e.terminal_id', 'c.legal_name'])->keyBy('id');

        return $locations->map(fn ($l) => [
            'location' => $l->location,
            'terminal' => $first[$l->first_id]->terminal_id ?? null,
            'cashier' => $first[$l->first_id]->legal_name ?? null,
            'sales' => $this->money($l->amount),
        ])->all();
    }
}
