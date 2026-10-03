<?php

namespace App\Services\Reports;

use App\Services\Reports\Concerns\InclusiveDateRange;
use Illuminate\Support\Facades\DB;

/**
 * "Reports > Agent Management" (legacy `agent_reports` controller + `agent_reports_model`).
 *
 * Legacy had a "List of Reports" dropdown fed by `value_sets_lookup` (set_type report_type, code agent); the table only
 * holds one entry, "ACL — Agent Commissions by Location Report", so that is the one report here and it opens directly.
 * The second section in the legacy view ("Master Agent Commissions", code MAC) can't be reached — no lookup row exists
 * for it — and was never finished (its table loads rows of empty cells, and its filter returns five unrelated fields
 * for six headers), so it isn't ported.
 *
 * Five columns — Location, Terminal, Cashier, Sales, Commission — one row per customer country, optionally limited to
 * a From/To range on the bill-payment date (applied only when both ends are given). Legacy built it from one chain of
 * joins grouped by country: `billpay_transactions` → `omni_commission_settings` (only billers with a commission row)
 * → `billers` → `ezkard_accounts` → `clients` → `terminals` → `customers` (by mobile). That chain is kept as is, so the
 * figures are the legacy ones, flaws included: a client's bills are repeated once per terminal it has (and once per
 * customer record sharing the mobile), so Sales are larger than the real total; and Terminal / Cashier / Commission are
 * simply one row of that country's group (MySQL returned whichever it met first). Here that row is picked
 * deterministically — the lowest commission-setting id, then the lowest client id, then that client's first terminal.
 * The work is done as a grouped query over (country, commission setting, client) plus a terminal count per client,
 * instead of materialising every bill x terminal x customer combination.
 */
class AgentManagementReportService
{
    use InclusiveDateRange;

    public const MODULE_PATH = '/reports/agent-management';

    public const COLUMNS = [
        ['key' => 'location', 'label' => 'Location'],
        ['key' => 'terminal', 'label' => 'Terminal'],
        ['key' => 'cashier', 'label' => 'Cashier'],
        ['key' => 'sales', 'label' => 'Sales'],
        ['key' => 'commission', 'label' => 'Commission'],
    ];

    private function db()
    {
        return DB::connection('mysuncash');
    }

    /** @param array{from?:?string,to?:?string} $f */
    public function list(array $f): array
    {
        $q = $this->db()->table('billpay_transactions as bt')
            ->join('omni_commission_settings as o', 'o.biller_code', '=', 'bt.biller_code')
            ->join('billers as b', 'b.biller_code', '=', 'bt.biller_code')
            ->join('ezkard_accounts as eza', 'eza.id', '=', 'bt.ezkard_id')
            ->join('clients as c', 'c.id', '=', 'eza.client_id')
            ->join('customers as cu', 'cu.mobile', '=', 'eza.mobile_number')
            ->groupBy('cu.country', 'o.id', 'c.id')
            ->selectRaw('cu.country, o.id AS oid, o.commission, c.id AS cid, c.legal_name, SUM(bt.bill_amount) AS bill');
        if ($range = $this->range($f['from'] ?? null, $f['to'] ?? null)) {
            $q->whereBetween('bt.transaction_date', $range);
        }
        $groups = $q->get();

        // Each bill is repeated per terminal of its client, and clients without a terminal drop out (inner join).
        $terminals = $this->db()->table('terminals')
            ->whereIn('client_id', $groups->pluck('cid')->unique()->all())
            ->orderBy('id')->get(['client_id', 'device_id'])->groupBy('client_id');

        $countries = [];
        foreach ($groups->sortBy([['oid', 'asc'], ['cid', 'asc']]) as $g) {
            $own = $terminals->get($g->cid);
            if (! $own) {
                continue;
            }
            $row = &$countries[(string) $g->country];
            $row ??= [
                'location' => $g->country,
                'terminal' => $own->first()->device_id,
                'cashier' => $g->legal_name,
                'sales' => 0.0,
                'commission' => number_format((float) $g->commission, 2).' BSD',
            ];
            $row['sales'] += (float) $g->bill * $own->count();
            unset($row);
        }

        // Legacy's GROUP BY returned the groups in country order.
        ksort($countries, SORT_STRING | SORT_FLAG_CASE);

        return array_values(array_map(fn ($r) => [...$r, 'sales' => number_format($r['sales'], 2).' BSD'], $countries));
    }
}
