<?php

namespace App\Services\Reports;

use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * "Reports > Settlement" (legacy `settlement` controller + `settlement_model`):
 * three tabs — Merchants, Suppliers, Revenues — each a per-client summary for
 * one day, with a per-client drill-down.
 *
 * Legacy ran six near-identical queries (summary + detail per tab) that
 * differed only in one WHERE clause. They share `base()` here, with the tab's
 * rule in `scope()`; the joins (including the ones that only filter rows —
 * `rev_share_transactions`, `clients`) and the counting semantics are
 * unchanged, so rows, counts and sums are the same.
 *
 * Changes that don't alter results:
 *  - `DATE_FORMAT(t.timestamp,'%Y-%m-%d') = day` -> `t.timestamp >= day AND
 *    < day+1` (same day, usable against idxTimestamp).
 *  - Summaries sorted by client (legacy relied on MySQL 5.7's implicit
 *    GROUP BY ordering); detail rows by transaction id so the list is stable.
 *  - Indexes on the two join keys that had none (see the accompanying
 *    migration): each client_transactions row was matched to its
 *    client_transaction_details / rev_share_transaction_details by full scan.
 *
 * `count`/`sum` are taken over the joined rows (as legacy did), so a
 * transaction with several revenue-share lines counts once per line.
 */
class SettlementReportService
{
    public const TABS = ['merchants', 'suppliers', 'revenues'];

    private const TIERS = [
        'merchants' => [1 => 'Direct POS Transaction Amount', 2 => 'Direct POS Transaction Fee', 3 => 'Indirect POS Commission', 4 => 'Non-POS Commision'],
        'suppliers' => [1 => 'Transaction Amount', 2 => 'Provider Fees'],
    ];

    private function base(string $tab, string $date): Builder
    {
        $start = Carbon::parse($date)->startOfDay();

        $query = DB::connection('mysuncash')->table('client_transactions as t')
            ->join('client_transaction_details as d', 't.id', '=', 'd.client_transaction_id')
            ->join('rev_share_transaction_details as rstd', 'rstd.revshare_log_id', '=', 'd.id')
            ->join('rev_share_transactions as rst', 'rst.id', '=', 'rstd.rev_share_id')
            ->leftJoin('rev_share_definitions as rsd', 'rstd.definition_id', '=', 'rsd.id')
            ->join('clients as c', 't.client_record_id', '=', 'c.id')
            ->where('d.client_account_type', 1) // 1 = settlement account
            ->where('t.timestamp', '>=', $start->toDateTimeString())
            ->where('t.timestamp', '<', $start->copy()->addDay()->toDateTimeString());

        return match ($tab) {
            'merchants' => $query->where('rstd.definition_id', 0),
            'suppliers' => $query->where('rstd.definition_id', '<>', 0)
                ->whereRaw('(rsd.share_type = 0 OR rstd.definition_id = -1)'),
            'revenues' => $query->where('rstd.definition_id', '<>', 0)->where('rsd.share_type', 1),
        };
    }

    public function summary(string $tab, string $date): array
    {
        $rows = $this->base($tab, $date)
            ->selectRaw('t.client_record_id, c.client_id, c.merchant_name, COUNT(*) as cnt, SUM(t.amount) as amt')
            ->groupBy('t.client_record_id')
            ->orderBy('t.client_record_id')
            ->get();

        $count = 0;
        $amount = 0.0;
        $data = [];
        foreach ($rows as $r) {
            $count += (int) $r->cnt;
            $amount += (float) $r->amt;
            $data[] = [
                'client_record_id' => (int) $r->client_record_id,
                'client_id' => $r->client_id,
                'merchant_name' => $r->merchant_name,
                'count' => (int) $r->cnt,
                'amount' => number_format((float) $r->amt, 4),
            ];
        }

        return ['data' => $data, 'total' => ['count' => $count, 'amount' => number_format($amount, 4)]];
    }

    public function details(string $tab, string $date, int $clientRecordId): array
    {
        $rows = $this->base($tab, $date)
            ->where('t.client_record_id', $clientRecordId)
            ->select(['t.id', 't.timestamp', 't.description', 't.amount', 'rstd.revshare_type', 'rstd.definition_id'])
            ->orderBy('t.id')->orderBy('rstd.id')
            ->get();

        $client = DB::connection('mysuncash')->table('clients')->where('id', $clientRecordId)->first(['client_id', 'merchant_name']);
        $merchant = $client ? $client->client_id.'/'.$client->merchant_name : 'Unknown';

        if ($tab === 'revenues') {
            return ['merchant' => $merchant, 'sections' => [array_diff_key($this->section(null, $rows), ['raw_total' => 1])], 'grand_total' => null];
        }

        // Merchants: by revshare_type 1-4. Suppliers: definition -1 is the transaction amount, anything else a provider fee.
        // Rows whose type falls outside the tiers aren't shown (nor counted in the grand total), as in legacy.
        $byTier = [];
        foreach ($rows as $r) {
            $tier = $tab === 'merchants' ? (int) $r->revshare_type : ((int) $r->definition_id === -1 ? 1 : 2);
            $byTier[$tier][] = $r;
        }

        $sections = [];
        $count = 0;
        $amount = 0.0;
        foreach (self::TIERS[$tab] as $tier => $name) {
            $section = $this->section("Settlement Type {$tier}: {$name}", $byTier[$tier] ?? []);
            $count += $section['count'];
            $amount += $section['raw_total'];
            $sections[] = $section;
        }

        return [
            'merchant' => $merchant,
            'sections' => array_map(fn ($s) => array_diff_key($s, ['raw_total' => 1]), $sections),
            'grand_total' => ['count' => $count, 'amount' => number_format($amount, 4)],
        ];
    }

    private function section(?string $title, iterable $rows): array
    {
        $total = 0.0;
        $out = [];
        foreach ($rows as $r) {
            $total += (float) $r->amount;
            $out[] = ['timestamp' => $r->timestamp, 'description' => $r->description, 'amount' => number_format((float) $r->amount, 4)];
        }

        return ['title' => $title, 'rows' => $out, 'count' => count($out), 'total' => number_format($total, 4), 'raw_total' => $total];
    }
}
