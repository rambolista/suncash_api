<?php

namespace App\Services\Reports;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * "Reports > Voucher" (legacy `voucher_report` controller + `voucher_model::getVoucherList`).
 *
 * One report with six filters: From/To date, Status, Voucher Product, Purchased Source and Redeemed Source.
 * The product picks the table — SunCash Voucher (product 1) reads `merchant_vouchers`; every other product reads
 * `universal_vouchers` (UniBucks = anything but product 3, Credit Voucher = product 3, exactly as legacy split them).
 * Same nine columns for all.
 *
 * Legacy built its SQL from six hard-coded branches that only half-agreed with each other. Against the real data
 * the Redeemed Source filter alone returned nothing for CustomerApp / CustomerPortal / WebPOS and a cartesian
 * 102,919 rows for 101 vouchers for Kiosk, Purchased Source "NIB" always returned nothing, several combinations
 * (e.g. WebPOS + WebPOS) crashed with an unknown-column SQL error, a typo (`'CustomerPortal' 0`) broke the UniBucks
 * CustomerPortal filter, and even the unfiltered list returned 13,523 rows for 9,201 vouchers because of one-to-many
 * joins whose columns weren't shown. Here every filter is a plain predicate on the same expressions the Purchase /
 * Redeemed Source columns display, so filtering by a source returns exactly the rows that show that source:
 *  - one row per voucher (unused joins dropped; batch/webpos lookups can't multiply rows);
 *  - Purchased Source CustomerApp / CustomerPortal / WebPOS / Kiosk = the voucher's `source`, still skipping
 *    test accounts (customer mobiles starting 639 / 1639) as legacy did — but a WebPOS / Kiosk voucher with no customer
 *    attached is kept (legacy's `NULL NOT LIKE` silently dropped them, which hid 2,600 of 2,691 WebPOS vouchers);
 *  - NIB = vouchers generated in an NIB batch; a merchant = `merchant_owner_id` / purchased_client_id;
 *  - Redeemed Source CustomerApp / CustomerPortal / Kiosk = the redeem remarks, WebPOS = a WebPOS cash-out voucher
 *    transaction exists, a merchant = `client_record_id` / redeemed_client_id.
 * Kept from legacy: the date column (the voucher date, but the redeem/void date when Status is Redeemed/Voided or
 * only a Redeemed Source is chosen), the 639/1639 test-account rule, the Redeemed/Voided Date being blank for ACTIVE
 * vouchers, and the product split.
 */
class VoucherReportService
{
    public const MODULE_PATH = '/reports/voucher';

    public const STATUSES = ['ALL', 'ACTIVE', 'REDEEMED', 'VOIDED'];

    public const PURCHASE_SOURCES = ['CustomerApp', 'CustomerPortal', 'WebPOS', 'NIB', 'Kiosk'];

    public const REDEEM_SOURCES = ['CustomerApp', 'CustomerPortal', 'WebPOS', 'Kiosk'];

    public const COLUMNS = [
        ['key' => 'voucher_date', 'label' => 'Voucher Date'],
        ['key' => 'voucher_number', 'label' => 'Voucher Number'],
        ['key' => 'amount', 'label' => 'Amount'],
        ['key' => 'mobile_number', 'label' => 'Mobile Number'],
        ['key' => 'email_address', 'label' => 'Email Address'],
        ['key' => 'purchase_source', 'label' => 'Purchase Source'],
        ['key' => 'redeemed_source', 'label' => 'Redeemed Source'],
        ['key' => 'status', 'label' => 'Status'],
        ['key' => 'redeemed_voided_date', 'label' => 'Redeemed / Voided Date'],
    ];

    /**
     * Customers on these number ranges are test accounts; legacy hid them whenever a customer-facing source was picked.
     * CustomerApp / CustomerPortal vouchers belong to a customer (their mobile is the Mobile Number column), so a missing
     * customer or mobile excludes them, as legacy did. WebPOS / Kiosk vouchers usually have no customer at all, which
     * legacy's `NULL NOT LIKE` wrongly treated as "test account" — those are kept.
     */
    private const TEST_MOBILE = "(cc.mobile NOT LIKE '639%' AND cc.mobile NOT LIKE '1639%')";

    private const TEST_MOBILE_OR_NONE = '(cc.mobile IS NULL OR '.self::TEST_MOBILE.')';

    private function db()
    {
        return DB::connection('mysuncash');
    }

    // ------------------------------------------------------------------ dropdowns

    public function options(): array
    {
        $merchants = $this->db()->table('clients as c')
            ->join('services_permission as sp', 'sp.client_record_id', '=', 'c.id')
            ->join('system_services as ss', 'ss.id', '=', 'sp.system_services_id')
            ->where('ss.code', 'SUNCASHVOUCHER')->where('sp.status', 'A')
            ->distinct()->orderBy('c.id')
            ->get(['c.id', 'c.dba_name', 'c.legal_name', 'c.client_id'])
            ->map(fn ($c) => ['value' => (string) $c->id, 'label' => filled($c->dba_name) ? $c->dba_name : (filled($c->legal_name) ? $c->legal_name : $c->client_id)])
            ->all();
        $fixed = fn (array $names) => array_map(fn ($n) => ['value' => $n, 'label' => $n], $names);

        return [
            'products' => $this->db()->table('voucher_products')->where('status', 'ACTIVE')->orderBy('id')
                ->get(['id', 'description'])->map(fn ($p) => ['value' => (string) $p->id, 'label' => $p->description])->all(),
            'purchased' => [...$fixed(self::PURCHASE_SOURCES), ...$merchants],
            'redeemed' => [...$fixed(self::REDEEM_SOURCES), ...$merchants],
        ];
    }

    // ------------------------------------------------------------------ report

    /** @param array{status:string,from:string,to:string,sku:int|string,purchased:?string,redeemed:?string} $f */
    public function list(array $f): array
    {
        $purchased = (string) ($f['purchased'] ?? '');
        $redeemed = (string) ($f['redeemed'] ?? '');
        // Legacy: the window is on the redeem/void date for those statuses, and when only a Redeemed Source is picked.
        $byUpdate = in_array($f['status'], ['REDEEMED', 'VOIDED'], true) || ($redeemed !== '' && $purchased === '');
        $dateCol = $byUpdate ? 'v.update_date' : 'v.voucher_date';

        $q = (int) $f['sku'] === 1 ? $this->merchantVouchers($purchased, $redeemed) : $this->universalVouchers((int) $f['sku'], $purchased, $redeemed);
        $q->where($dateCol, '>=', $f['from'].' 00:00:00')->where($dateCol, '<', date('Y-m-d', strtotime($f['to'].' +1 day')).' 00:00:00')
            ->when($f['status'] !== 'ALL', fn ($q) => $q->where('v.status', $f['status']))
            ->orderByDesc('v.voucher_date')->orderByDesc('v.id');

        return $q->get()->map(fn ($r) => [
            'voucher_date' => $r->voucher_date,
            'voucher_number' => $r->voucher_number,
            'amount' => $r->amount,
            'mobile_number' => $r->mobile_number,
            'email_address' => $r->email_address,
            'purchase_source' => $r->purchase_source,
            'redeemed_source' => $r->redeemed_source,
            'status' => $r->status,
            'redeemed_voided_date' => $r->status === 'ACTIVE' ? '' : $r->redeemed_voided_date,
        ])->all();
    }

    /** SunCash Voucher: `merchant_vouchers`. */
    private function merchantVouchers(string $purchased, string $redeemed): Builder
    {
        $cashout = "EXISTS (SELECT 1 FROM webpos_transaction w2 WHERE w2.reference = v.voucher_code AND w2.transaction_type = 'CASHOUT_VOUCHER')";
        $nibBatch = "EXISTS (SELECT 1 FROM batch_voucher_generation b WHERE b.voucher_number = v.voucher_code AND b.field1 = 'NIB')";

        $q = $this->db()->table('merchant_vouchers as v')
            ->leftJoin('clients as c', 'c.id', '=', 'v.client_record_id')
            ->leftJoin('clients as c2', 'c2.id', '=', 'v.merchant_owner_id')
            ->leftJoin('customers as cc', 'cc.id', '=', 'v.customer_owner_id')
            ->selectRaw("v.id, v.voucher_code AS voucher_number, v.voucher_date, v.amount, v.email AS email_address, v.status, v.update_date AS redeemed_voided_date,
                CASE WHEN v.source = 'CustomerApp' THEN 'CustomerApp'
                     WHEN v.source = 'CustomerPortal' THEN 'CustomerPortal'
                     WHEN v.source = 'WebPOS' THEN 'WebPOS'
                     WHEN c2.legal_name = '' THEN c2.dba_name
                     ELSE IFNULL(c2.legal_name, IFNULL((SELECT b.field1 FROM batch_voucher_generation b WHERE b.voucher_number = v.voucher_code LIMIT 1), v.source)) END AS purchase_source,
                CASE WHEN v.source IN ('CustomerApp', 'CustomerPortal') THEN cc.mobile ELSE v.mobile END AS mobile_number,
                CASE WHEN v.remarks LIKE '%CustomerApp%' THEN 'CustomerApp'
                     WHEN v.remarks LIKE '%CustomerPortal%' THEN 'CustomerPortal'
                     WHEN c.legal_name = '' THEN c.dba_name
                     WHEN {$cashout} THEN 'WebPOS'
                     ELSE c.legal_name END AS redeemed_source");

        match (true) {
            $purchased === '' => null,
            $purchased === 'CustomerApp' => $q->where('v.customer_owner_id', '>', 0)->where('v.source', 'CustomerApp')->whereRaw(self::TEST_MOBILE),
            $purchased === 'CustomerPortal' => $q->where('v.source', 'CustomerPortal')->whereRaw(self::TEST_MOBILE),
            in_array($purchased, ['WebPOS', 'Kiosk'], true) => $q->where('v.source', $purchased)->whereRaw(self::TEST_MOBILE_OR_NONE),
            $purchased === 'NIB' => $q->whereRaw($nibBatch),
            default => $q->where('v.merchant_owner_id', $purchased),
        };
        match (true) {
            $redeemed === '' => null,
            in_array($redeemed, ['CustomerApp', 'CustomerPortal', 'Kiosk'], true) => $q->where('v.remarks', 'like', "%{$redeemed}%"),
            $redeemed === 'WebPOS' => $q->whereRaw($cashout),
            default => $q->where('v.client_record_id', $redeemed),
        };

        return $q;
    }

    /** UniBucks (any product but 3) and Credit Voucher (product 3): `universal_vouchers`, which legacy INNER-joined to its action log. */
    private function universalVouchers(int $sku, string $purchased, string $redeemed): Builder
    {
        $client = fn (string $id) => "(SELECT IFNULL(CASE WHEN legal_name = '' THEN dba_name ELSE legal_name END, '') FROM clients WHERE id = v.{$id})";

        $q = $this->db()->table('universal_vouchers as v')
            ->whereExists(fn ($s) => $s->selectRaw('1')->from('universal_vouchers_logs as uvl')->whereColumn('uvl.universal_vouchers_id', 'v.id'))
            ->where('v.voucher_product_id', $sku === 3 ? '=' : '!=', 3)
            ->groupBy('v.voucher_code')
            ->selectRaw("v.id, v.voucher_code AS voucher_number, v.voucher_date, v.amount, v.receiver_mobile AS mobile_number, v.receiver_email AS email_address, v.status, v.update_date AS redeemed_voided_date,
                CASE WHEN v.purchased_channel = '3rdParty' THEN {$client('purchased_client_id')} ELSE v.purchased_channel END AS purchase_source,
                CASE WHEN v.redeemed_channel = '3rdParty' THEN {$client('redeemed_client_id')} ELSE v.redeemed_channel END AS redeemed_source");

        match (true) {
            $purchased === '' => null,
            $purchased === 'NIB' => $q->whereRaw('1 = 0'), // legacy: "there is no NIB in UniBucks"
            in_array($purchased, ['CustomerApp', 'CustomerPortal', 'WebPOS', 'Kiosk'], true) => $q->where('v.purchased_channel', $purchased),
            default => $q->where('v.purchased_client_id', $purchased),
        };
        match (true) {
            $redeemed === '' => null,
            in_array($redeemed, ['CustomerApp', 'CustomerPortal', 'WebPOS', 'Kiosk'], true) => $q->where('v.redeemed_channel', $redeemed),
            default => $q->where('v.redeemed_client_id', $redeemed),
        };

        return $q;
    }
}
