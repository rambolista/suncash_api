<?php

namespace App\Services\Reports;

use App\Services\Reports\Concerns\InclusiveDateRange;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * "Reports > Mobile Top Up" (legacy `topup_reports` controller + `topup_reports_model`).
 *
 * Two filters beside the date range — the customer's Account No. (mobile) and the Provider — over nine columns.
 * Legacy stitched two queries together: WebPOS merchant top-ups (`source = 4`, which need their `topup` row in
 * `webpos_transaction_3rdparty`, shown under the merchant's DBA name and never merchant 3065) and everything raised
 * from WebPOS / Customer App / Kiosk (`source` 1-3). Kept as is: that split, the "blank provider on a WebPOS top-up
 * means paynation" rule, the date filter applying only when both ends are given, newest first, and the 5,000-row cap
 * on what is listed.
 *
 * Bug fixed: legacy joined `customers` on `ezkard_account_id = ezkard_id`, but every Kiosk and WebPOS top-up has
 * `ezkard_id = -1` (no customer) and sixteen sample customers also carry `ezkard_account_id = -1`. Each of those
 * top-ups therefore appeared sixteen times, once per sample customer, with a made-up name / mobile / email — on the
 * dev data 1,945 real top-ups came out as 14,110 rows, so the 5,000-row cap cut the real list short. Here a top-up
 * only picks up a customer when it has a real account (`ezkard_id > 0`) and only the first customer record for it
 * (two records share one account), so every top-up is listed once and walk-in ones show no customer.
 * Two deliberate differences remain: the Account No. dropdown lists only customers who have topped up (legacy listed
 * every customer, repeats and blanks included), and the export isn't capped at 5,000 rows.
 */
class MobileTopupReportService
{
    use InclusiveDateRange;

    public const MODULE_PATH = '/reports/mobile-topup';

    public const MAX_ROWS = 5000;

    public const COLUMNS = [
        ['key' => 'transaction_date', 'label' => 'Date'],
        ['key' => 'customer_name', 'label' => 'Customer Name'],
        ['key' => 'customer_mobile', 'label' => 'Customer Mobile'],
        ['key' => 'customer_email', 'label' => 'Customer Email'],
        ['key' => 'bene_mobile', 'label' => 'Bene Mobile'],
        ['key' => 'amount', 'label' => 'Amount'],
        ['key' => 'product_id', 'label' => 'Product ID'],
        ['key' => 'provider', 'label' => 'Provider'],
        ['key' => 'source', 'label' => 'Source'],
    ];

    private const PROVIDER_CASE = "CASE WHEN mtt.provider = '' AND mtt.source = 4 THEN 'paynation' ELSE mtt.provider END";

    private function db()
    {
        return DB::connection('mysuncash');
    }

    // ------------------------------------------------------------------ dropdowns

    public function options(): array
    {
        return [
            'accounts' => $this->db()->table('customers as c')
                ->where('c.mobile', '!=', '')->where('c.ezkard_account_id', '>', 0)
                ->whereExists(fn ($q) => $q->selectRaw('1')->from('mobile_topup_transactions as mtt')->whereIn('mtt.source', [1, 2, 3])->whereColumn('mtt.ezkard_id', 'c.ezkard_account_id'))
                ->distinct()->orderBy('c.mobile')->pluck('c.mobile')->all(),
            // Same expression as the Provider column, so every listed value filters to rows that show it.
            'providers' => $this->db()->table('mobile_topup_transactions as mtt')
                ->whereIn('mtt.source', [1, 2, 3, 4])
                ->selectRaw('DISTINCT '.self::PROVIDER_CASE.' AS provider')
                ->havingRaw("provider IS NOT NULL AND provider != ''")->orderBy('provider')
                ->pluck('provider')->all(),
        ];
    }

    // ------------------------------------------------------------------ listing

    /** @param array{from?:?string,to?:?string,account?:?string,provider?:?string} $f */
    private function base(Builder $q, array $f): Builder
    {
        // A top-up has a customer only with a real account, and only the first record when two share it.
        $q->leftJoin('customers as c', function ($j) {
            $j->on('c.ezkard_account_id', '=', 'mtt.ezkard_id')->where('mtt.ezkard_id', '>', 0)
                ->whereRaw('c.id = (SELECT MIN(c2.id) FROM customers c2 WHERE c2.ezkard_account_id = c.ezkard_account_id)');
        });

        if ($range = $this->range($f['from'] ?? null, $f['to'] ?? null)) {
            $q->whereBetween('mtt.transaction_date', $range);
        }
        if (filled($f['account'] ?? null)) {
            $q->where('c.mobile', $f['account']);
        }

        return $q;
    }

    private function union(array $f): Builder
    {
        $common = [
            'mtt.id', 'mtt.transaction_date',
            DB::raw("CONCAT(c.first_name, ' ', c.last_name) AS customer_name"),
            'c.mobile AS customer_mobile', 'c.email AS customer_email',
            'mtt.mobile_number AS bene_mobile', 'mtt.amount', 'mtt.product_id',
        ];

        // WebPOS merchant top-ups: need their 3rd-party row and a merchant other than 3065; Source = the merchant.
        $merchant = $this->base($this->db()->table('mobile_topup_transactions as mtt'), $f)
            ->join('webpos_transaction_3rdparty as w', fn ($j) => $j->on('w.trans_ref_id', '=', 'mtt.id')->where('w.transaction_type', 'topup'))
            ->join('clients as cc', 'cc.id', '=', 'w.merchant_id')
            ->where('mtt.source', 4)->where('cc.id', '!=', 3065)
            ->select([...$common, DB::raw(self::PROVIDER_CASE.' AS provider'), 'cc.dba_name AS source']);

        $channels = $this->base($this->db()->table('mobile_topup_transactions as mtt'), $f)
            ->whereIn('mtt.source', [1, 2, 3])
            ->select([...$common, 'mtt.provider', DB::raw("CASE mtt.source WHEN 1 THEN 'WEBPOS' WHEN 2 THEN 'CUSTOMER_APP' ELSE 'KIOSK' END AS source")]);

        if (filled($f['provider'] ?? null)) {
            $merchant->whereRaw('('.self::PROVIDER_CASE.') = ?', [$f['provider']]);
            $channels->where('mtt.provider', $f['provider']);
        }

        return $merchant->unionAll($channels);
    }

    /** Newest first, as legacy. `$limit = null` lifts the cap (export). */
    public function list(array $f, ?int $limit = self::MAX_ROWS): array
    {
        $q = $this->db()->query()->fromSub($this->union($f), 't')->orderByDesc('transaction_date')->orderByDesc('id');
        if ($limit !== null) {
            $q->limit($limit);
        }

        return $q->get()->map(fn ($r) => [
            'transaction_date' => $r->transaction_date,
            'customer_name' => $r->customer_name,
            'customer_mobile' => $r->customer_mobile,
            'customer_email' => $r->customer_email,
            'bene_mobile' => $r->bene_mobile,
            'amount' => number_format((float) $r->amount, 2).' BSD',
            'product_id' => $r->product_id,
            'provider' => $r->provider,
            'source' => $r->source,
        ])->all();
    }

    /** Count / total of every matching top-up, plus a note when the 5,000-row cap hides some from the list. */
    public function summary(array $f, bool $capped = true): array
    {
        $row = $this->db()->query()->fromSub($this->union($f), 't')->selectRaw('COUNT(*) AS n, COALESCE(SUM(amount), 0) AS total')->first();
        $summary = ['Total Count' => number_format((int) $row->n), 'Total Amount' => number_format((float) $row->total, 2).' BSD'];
        if ($capped && $row->n > self::MAX_ROWS) {
            $summary['Showing'] = 'Newest '.number_format(self::MAX_ROWS).' of '.number_format((int) $row->n).' — narrow the date range';
        }

        return $summary;
    }
}
