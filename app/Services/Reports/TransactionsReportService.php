<?php

namespace App\Services\Reports;

use Carbon\Carbon;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * "Reports > Transactions" (legacy `reports_` controller's AJAX
 * `loadReportPerTransaction` summary + `loadReportPerTransaction_Detailed`
 * list, backed by `transactions_model`).
 *
 * Output is intentionally identical to legacy, quirks included — only HOW
 * it's computed changed:
 *
 *  - Summary: legacy ran up to ~25 separate queries (one per transaction
 *    type, each also repeated for the "All" view), pulled every matching
 *    row into PHP and summed them in a loop. Here the whole webpos family is
 *    ONE `GROUP BY type, exclude_fee` aggregate, and the per-type formulas
 *    (`fold()`) are applied to those group sums. Every formula in
 *    `constructPerTransactionData` is linear in the raw columns once
 *    `exclude_fee` is fixed, so the numbers are the same (checked against a
 *    literal port of the legacy fetch-all-rows-and-loop algorithm over the
 *    dev data: every type x merchant x user/branch filter x date range).
 *  - Dates: `CAST(col AS DATE) BETWEEN a AND b` can't use an index; the
 *    equivalent half-open `col >= a 00:00 AND col < b+1 00:00` can.
 *  - Joins that can never change the row set or any displayed column
 *    (terminals / device_types / clients / transaction_types / status /
 *    ezkard_accounts, all unique-key LEFT JOINs) are dropped; joins that DO
 *    affect rows (branch filter, `merchant_terminal_users` user filter,
 *    ezkard_transactions' timestamp/duplication, billpay_web_transactions)
 *    are kept exactly.
 *  - Detail lists return the full set; the page's DataTable pages/sorts/searches them (as legacy showed one full list).
 *
 * Legacy quirks deliberately preserved (change them = change the report):
 *  - With a User filter the summary uses a different formula (no
 *    exclude_fee handling, facility/sunpass fees included in the total).
 *  - Summary treats NULL `exclude_fee` as 1 (`isset()`), the detail list
 *    treats it as 0 (`NULL == 0`) — so a NULL-exclude_fee row's amount
 *    differs between the two views.
 *  - WU EOD rows have no `user_id`, so any User filter yields 0 for them.
 *  - Check Cashing / Check On Hand compare the User filter against the
 *    TERMINAL id (`t.id AS user_id`), not the user's id.
 *  - Void (Cashout) detail is driven by `ezkard_transactions` and has no
 *    `exclude_fee` column, so it renders as if `exclude_fee = 0`.
 *  - User column is `first_name . last_name` with no separator.
 */
class TransactionsReportService
{
    /** Legacy `Reports_::$transaction_types` — order drives summary row order. */
    public const TYPES = [
        '' => 'All',
        'TICKETS' => 'Tickets',
        'TICKETS_MOVIE' => 'Tickets (MOVIE)',
        'LOAD' => 'Load',
        'PURCHASE' => 'Sale/Purchase',
        'CASHOUT_CODE' => 'Cashout (Code)',
        'CASHOUT_MOBILE' => 'Cashout (Mobile)',
        'CHECK_CASHING' => 'Check Cashing',
        'CHECK_ON_HAND' => 'Check On Hand',
        'BANK_DEPOSIT' => 'Bank Deposit',
        'BILLPAY' => 'Billpay',
        'BILLPAY_BUSINESS' => 'Billpay for Business',
        'DONATION' => 'Donation',
        'GAMING_HOUSE_DEPOSIT' => 'Gaming Deposit',
        'GAMING_HOUSE_WITHDRAW' => 'Gaming Withdraw',
        'GOVERNMENT_PAYMENT' => 'Government Payment',
        'TOPUP' => 'Mobile Topup',
        'MONEY_TRANSFER' => 'Money Transfer',
        'VOID_REGULAR' => 'Void',
        'VOID_CASHOUT' => 'Void (Cashout)',
        'DIGITALCHECK' => 'Digital Check',
        'SUNCASH_VOUCHER' => 'Suncash Voucher',
        'REDEEMED_SUNCASH_VOUCHER' => 'Redeemed Suncash Voucher',
        'UNIBUCKS_VOUCHER' => 'Unibucks Voucher',
        'REDEEMED_UNIBUCKS_VOUCHER' => 'Redeemed Unibucks Voucher',
        'WU_EOD_PAYOUT' => 'WU EOD PAYOUT',
        'WU_EOD_REFUND' => 'WU EOD REFUND',
        'WU_EOD_CANCELLATION' => 'WU EOD CANCELLATION',
        'WU_EOD_SEND' => 'WU EOD SEND',
    ];

    public const SUMMARY_COLUMNS = [
        ['key' => 'type', 'label' => 'Transaction Type'],
        ['key' => 'count', 'label' => 'Count'],
        ['key' => 'amount', 'label' => 'Amount'],
        ['key' => 'fee', 'label' => 'Fee'],
        ['key' => 'total', 'label' => 'Total Amount'],
    ];

    /** Types read straight from `webpos_transaction` by type name (BILLPAY_BUSINESS = BILLPAY with a "00" id prefix). */
    private const WEBPOS = [
        'GOVERNMENT_PAYMENT', 'GAMING_HOUSE_DEPOSIT', 'GAMING_HOUSE_WITHDRAW', 'BANK_DEPOSIT', 'TICKETS', 'TICKETS_MOVIE',
        'LOAD', 'PURCHASE', 'CASHOUT_CODE', 'CASHOUT_MOBILE', 'BILLPAY', 'BILLPAY_BUSINESS', 'DONATION', 'TOPUP',
        'MONEY_TRANSFER', 'DIGITALCHECK',
    ];

    private const CHECKS = ['CHECK_ON_HAND', 'CHECK_CASHING'];

    private const WU = ['WU_EOD_PAYOUT' => 'payout', 'WU_EOD_REFUND' => 'refund', 'WU_EOD_CANCELLATION' => 'cancellation', 'WU_EOD_SEND' => 'send'];

    private const CASHOUT_TYPES = ['CASHOUT_MOBILE', 'CASHOUT_CODE'];

    private const VOUCHERS = ['SUNCASH_VOUCHER', 'REDEEMED_SUNCASH_VOUCHER', 'UNIBUCKS_VOUCHER', 'REDEEMED_UNIBUCKS_VOUCHER'];

    /** Shown red and subtracted from the grand total. */
    private const NEGATIVE = ['CASHOUT_CODE', 'CASHOUT_MOBILE', 'VOID_REGULAR', 'REDEEMED_SUNCASH_VOUCHER', 'REDEEMED_UNIBUCKS_VOUCHER'];

    private ?array $voucherWebposClients = null;

    private function db(): ConnectionInterface
    {
        return DB::connection('mysuncash');
    }

    // ---------------------------------------------------------------- lookups

    /** Legacy `clients_model::client_list()` — every client, "ClientId-MerchantName". */
    public function merchants(): array
    {
        return $this->db()->table('clients')
            ->orderBy('client_id')->orderByDesc('merchant_name')
            ->get(['id', 'client_id', 'merchant_name'])
            ->map(fn ($c) => ['id' => (int) $c->id, 'label' => $c->client_id.'-'.$c->merchant_name])
            ->all();
    }

    /** Legacy `merchant_terminal_user_list($merchant, $branch)`. `merchant_id` is a VARCHAR column — keep the bind a string so its index is usable. */
    public function users(int $merchantId, ?int $branchId): array
    {
        return $this->db()->table('merchant_terminal_users')
            ->where('merchant_id', (string) $merchantId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->get(['id', 'user_name'])
            ->map(fn ($u) => ['id' => (int) $u->id, 'label' => $u->user_name])
            ->all();
    }

    /** Legacy `getBranchListByMerchant()` — branches that have at least one of the merchant's users. */
    public function branches(int $merchantId): array
    {
        return $this->db()->table('branch as b')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('merchant_terminal_users as m')
                ->whereColumn('m.branch_id', 'b.id')->where('m.merchant_id', (string) $merchantId))
            ->orderBy('b.description')
            ->get(['b.id', 'b.description'])
            ->map(fn ($b) => ['id' => (int) $b->id, 'label' => $b->description])
            ->all();
    }

    public function typeOptions(): array
    {
        return collect(self::TYPES)->map(fn ($label, $key) => ['value' => $key, 'label' => $label])->values()->all();
    }

    // ---------------------------------------------------------------- summary

    /** @return array{data: list<array>, total: array, from: string, to: string} */
    public function summary(int $merchantId, string $typeKey, ?int $userId, ?int $branchId, string $from, string $to): array
    {
        [$start, $end] = $this->range($from, $to);
        $userMode = ($userId ?? 0) > 0;
        $keys = $typeKey === '' ? array_values(array_filter(array_keys(self::TYPES))) : [$typeKey];

        $groups = [];
        $this->webposGroups($groups, $keys, $merchantId, $userMode ? $userId : null, $branchId, $start, $end);
        $this->checkGroups($groups, $keys, $merchantId, $userMode ? $userId : null, $branchId, $start, $end);
        $this->wuGroups($groups, $keys, $merchantId, $userMode, $branchId, $start, $end);
        $this->voidGroups($groups, $keys, $merchantId, $userMode ? $userId : null, $branchId, $start, $end);
        $this->voucherGroups($groups, $keys, $merchantId, $userMode ? $userId : null, $branchId, $from, $to);

        $rows = [];
        $total = ['count' => 0, 'amount' => 0.0, 'fee' => 0.0, 'total' => 0.0];
        foreach (array_keys(self::TYPES) as $key) {
            if ($key === '' || ! in_array($key, $keys, true)) {
                continue;
            }
            $r = $this->fold($key, $groups[$key] ?? [], $userMode);
            // Legacy multipliers: cashouts/voids/redeemed vouchers are subtracted from the grand total; a gaming
            // withdrawal subtracts its amount but ADDS its fee.
            $sign = in_array($key, self::NEGATIVE, true) || $key === 'GAMING_HOUSE_WITHDRAW' ? -1 : 1;
            $feeSign = in_array($key, self::NEGATIVE, true) ? -1 : 1;
            $negative = $sign === -1;
            $feeNegative = $feeSign === -1;

            $total['count'] += $r['count'];
            $total['amount'] += $r['amount'] * $sign;
            $total['fee'] += $r['fee'] * $feeSign;
            $total['total'] += $r['total'] * $sign;

            $rows[] = [
                'key' => $key,
                'type' => self::TYPES[$key],
                'count' => $r['count'],
                'amount' => $this->money($r['amount']),
                'fee' => $this->money($r['fee']),
                'total' => $this->money($r['total']),
                'negative' => $negative,
                'fee_negative' => $feeNegative,
            ];
        }

        return [
            'data' => $rows,
            'total' => [
                'count' => $total['count'],
                'amount' => $this->money($total['amount']),
                'fee' => $this->money($total['fee']),
                'total' => $this->money($total['total']),
            ],
            'from' => $from,
            'to' => $to,
        ];
    }

    /**
     * Legacy `constructPerTransactionData()` over pre-aggregated groups
     * (one per distinct exclude_fee). $g keys: ef, cnt, amount, fee, vat, stamp, facility, sunpass.
     */
    private function fold(string $key, array $groups, bool $userMode): array
    {
        $count = 0;
        $total = $fee = $gross = 0.0;

        foreach ($groups as $g) {
            $ef = (int) $g['ef'];
            $amount = (float) $g['amount'];
            $f3 = (float) $g['fee'] + (float) $g['vat'] + (float) $g['stamp'];
            $extra = (float) $g['facility'] + (float) $g['sunpass'];
            $count += (int) $g['cnt'];

            if ($userMode) {
                $total += $amount;
                $fee += $f3 + $extra;
                $gross += $f3 + $amount + $extra;

                continue;
            }

            $total += $ef === 0 ? $amount - $f3 : $amount;
            $fee += $key === 'PURCHASE' ? (float) $g['fee'] : $f3 + $extra;
            $gross += match (true) {
                in_array($key, ['CASHOUT_MOBILE', 'BANK_DEPOSIT'], true) => $ef === 1 ? $f3 + $amount : $amount,
                $key === 'CHECK_ON_HAND' => $amount,
                $key === 'GAMING_HOUSE_WITHDRAW' => $amount - $f3,
                $key === 'PURCHASE' => $amount + (float) $g['fee'],
                default => $f3 + $amount,
            };
        }

        if (! $userMode && $key === 'CHECK_ON_HAND') {
            $fee = 0.0;
        }

        return ['count' => $count, 'amount' => $total, 'fee' => $fee, 'total' => $gross];
    }

    private const SUMS = 'COUNT(*) as cnt, COALESCE(SUM(%1$s.amount),0) as amount, COALESCE(SUM(%1$s.fee_amount),0) as fee, '
        .'COALESCE(SUM(%1$s.vat_amount),0) as vat, COALESCE(SUM(%1$s.stamp_amount),0) as stamp, '
        .'COALESCE(SUM(%1$s.facility_fee),0) as facility, COALESCE(SUM(%1$s.sunpass_fee),0) as sunpass';

    private function sums(string $alias): string
    {
        return sprintf(self::SUMS, $alias);
    }

    private function webposGroups(array &$groups, array $keys, int $merchantId, ?int $userId, ?int $branchId, string $start, string $end): void
    {
        $wanted = array_values(array_intersect($keys, self::WEBPOS));
        if (! $wanted) {
            return;
        }

        $wantsBill = in_array('BILLPAY', $wanted, true);
        $wantsBiz = in_array('BILLPAY_BUSINESS', $wanted, true);
        $dbTypes = array_values(array_unique(array_map(fn ($k) => $k === 'BILLPAY_BUSINESS' ? 'BILLPAY' : $k, $wanted)));

        $q = $this->db()->table('webpos_transaction as a')
            ->selectRaw("CASE WHEN a.transaction_type = 'BILLPAY' THEN IF(LEFT(a.transaction_id,2) = '00','BILLPAY_BUSINESS','BILLPAY') ELSE a.transaction_type END as bucket, "
                .'COALESCE(a.exclude_fee,1) as ef, '.$this->sums('a'))
            ->where('a.merchant_id', $merchantId)
            ->whereIn('a.transaction_type', $dbTypes)
            ->where('a.transaction_date', '>=', $start)->where('a.transaction_date', '<', $end)
            ->where('a.status', '!=', -2)
            ->groupBy('bucket', 'ef');

        // Legacy: BILLPAY = id NOT starting "00", BILLPAY_BUSINESS = id starting "00"; a NULL id is in neither.
        if ($wantsBill && $wantsBiz) {
            $q->whereRaw("(a.transaction_type <> 'BILLPAY' OR a.transaction_id IS NOT NULL)");
        } elseif ($wantsBill) {
            $q->whereRaw("(a.transaction_type <> 'BILLPAY' OR LEFT(a.transaction_id,2) <> '00')");
        } elseif ($wantsBiz) {
            $q->whereRaw("(a.transaction_type <> 'BILLPAY' OR LEFT(a.transaction_id,2) = '00')");
        }

        $this->scopeUserBranch($q, 'a', $userId, $branchId);

        foreach ($q->get() as $row) {
            $groups[$row->bucket][] = (array) $row;
        }
    }

    /** Check Cashing and Check On Hand are the same rows (`CHECKCASHING`, no status filter) folded two ways. */
    private function checkGroups(array &$groups, array $keys, int $merchantId, ?int $userId, ?int $branchId, string $start, string $end): void
    {
        $wanted = array_values(array_intersect($keys, self::CHECKS));
        if (! $wanted) {
            return;
        }

        $q = $this->db()->table('webpos_transaction as wt')
            ->selectRaw('COALESCE(wt.exclude_fee,1) as ef, '.$this->sums('wt'))
            ->where('wt.merchant_id', $merchantId)
            ->where('wt.transaction_type', 'CHECKCASHING')
            ->where('wt.transaction_date', '>=', $start)->where('wt.transaction_date', '<', $end)
            ->groupBy('ef');

        if ($userId) {
            // Legacy quirk: its `user_id` column here is `terminals.id`, so the PHP-side user match compares against the terminal id.
            $q->where('wt.terminal_user_id', $userId)
                ->join('terminals as t', fn ($j) => $j->on('t.id', '=', 'wt.terminal_id')->where('t.id', '=', $userId));
        }
        if ($branchId) {
            $q->join('branch as b', 'b.id', '=', 'wt.branch_id')->where('b.id', $branchId);
        }

        $rows = array_map(fn ($r) => (array) $r, $q->get()->all());
        foreach ($wanted as $key) {
            $groups[$key] = $rows;
        }
    }

    private function wuGroups(array &$groups, array $keys, int $merchantId, bool $userMode, ?int $branchId, string $start, string $end): void
    {
        $wanted = array_intersect_key(self::WU, array_flip($keys));
        if (! $wanted || $userMode) {
            // Legacy: the WU query never selects `user_id`, so a User filter matches nothing.
            return;
        }

        $q = $this->db()->table('wu_eod_webpos as a')
            ->selectRaw('a.type as bucket, 1 as ef, COUNT(*) as cnt, COALESCE(SUM(a.amount),0) as amount, 0 as fee, 0 as vat, 0 as stamp, 0 as facility, 0 as sunpass')
            ->where('a.merchant_id', $merchantId)
            ->whereIn('a.type', array_values($wanted))
            ->where('a.timestamp', '>=', $start)->where('a.timestamp', '<', $end)
            ->where('a.status', 0)
            ->groupBy('a.type');

        if ($branchId) {
            $q->join('branch as e', 'e.id', '=', 'a.branch_id')->where('e.id', $branchId);
        }

        $byType = array_flip(self::WU);
        foreach ($q->get() as $row) {
            $groups[$byType[$row->bucket]][] = (array) $row;
        }
    }

    private function voidGroups(array &$groups, array $keys, int $merchantId, ?int $userId, ?int $branchId, string $start, string $end): void
    {
        foreach (['VOID_REGULAR' => false, 'VOID_CASHOUT' => true] as $key => $cashout) {
            if (! in_array($key, $keys, true)) {
                continue;
            }

            // Legacy `gift_card_void_report_by_client_record_id()`: voided (status 1) webpos rows, de-duplicated by transaction_id.
            $inner = $this->db()->table('webpos_transaction as w')
                ->select(['w.transaction_id', 'w.amount', 'w.fee_amount', 'w.vat_amount', 'w.stamp_amount', 'w.facility_fee', 'w.sunpass_fee'])
                ->where('w.merchant_id', $merchantId)
                ->where('w.status', 1)
                ->where('w.transaction_date', '>=', $start)->where('w.transaction_date', '<', $end)
                ->when($cashout, fn ($q) => $q->whereIn('w.transaction_type', self::CASHOUT_TYPES), fn ($q) => $q->whereNotIn('w.transaction_type', self::CASHOUT_TYPES))
                ->groupBy('w.transaction_id');
            if ($userId) {
                $inner->where('w.terminal_user_id', $userId);
            }
            if ($branchId) {
                $inner->where('w.branch_id', (string) $branchId);
            }

            $row = $this->db()->query()->fromSub($inner, 't')
                ->selectRaw('1 as ef, '.$this->sums('t'))
                ->first();

            $groups[$key] = (int) $row->cnt > 0 ? [(array) $row] : [];
        }
    }

    private function voucherGroups(array &$groups, array $keys, int $merchantId, ?int $userId, ?int $branchId, string $from, string $to): void
    {
        foreach (array_intersect(self::VOUCHERS, $keys) as $key) {
            [$sql, $bindings] = $this->voucherSql($key, $merchantId, $branchId, $from, $to);
            $outer = $this->db()->query()->fromRaw("({$sql}) as t", $bindings)
                ->selectRaw('1 as ef, COUNT(*) as cnt, COALESCE(SUM(t.amount),0) as amount, 0 as fee, 0 as vat, 0 as stamp, 0 as facility, 0 as sunpass');
            if ($userId) {
                $outer->where('t.user_id', $userId);
            }
            $row = $outer->first();
            $groups[$key] = (int) $row->cnt > 0 ? [(array) $row] : [];
        }
    }

    // ---------------------------------------------------------------- detail

    /**
     * Column set for a type's detail list (also the export columns). The
     * Receipt action isn't a column — see `receipt_type` on each row.
     */
    public function detailColumns(string $key): array
    {
        if (in_array($key, self::VOUCHERS, true)) {
            return [
                ['key' => 'timestamp', 'label' => 'Voucher Date/Time'], ['key' => 'trans_type', 'label' => 'Voucher Type'],
                ['key' => 'voucher_code', 'label' => 'Voucher Code'], ['key' => 'purchase_source', 'label' => 'Purchased Source'],
                ['key' => 'amount', 'label' => 'Amount'], ['key' => 'fee', 'label' => 'Fee'], ['key' => 'total', 'label' => 'Total Amount'],
                ['key' => 'owner', 'label' => 'Voucher Owner'], ['key' => 'status', 'label' => 'Status'],
                ['key' => 'redeemed_source', 'label' => 'Redeemed Source'], ['key' => 'redeemed_date', 'label' => 'Redeemed Date/Time'],
            ];
        }

        $cols = [['key' => 'timestamp', 'label' => 'Date/Time'], ['key' => 'trans_type', 'label' => 'Trans Type']];
        if (in_array($key, ['BILLPAY', 'BILLPAY_BUSINESS'], true)) {
            $cols[] = ['key' => 'biller_info', 'label' => 'Biller Info'];
        }
        array_push($cols, ['key' => 'transaction_id', 'label' => 'Trans ID'], ['key' => 'terminal_id', 'label' => 'Terminal ID']);
        if ($key === 'LOAD') {
            array_push($cols, ['key' => 'customer_name', 'label' => 'Customer Name'], ['key' => 'customer_mobile', 'label' => 'Customer Mobile']);
        }
        array_push($cols, ['key' => 'amount', 'label' => 'Amount'], ['key' => 'fee', 'label' => 'Fee'], ['key' => 'total', 'label' => 'Total Amount'], ['key' => 'user', 'label' => 'User']);

        return $cols;
    }

    public function hasReceipt(string $key): bool
    {
        return ! in_array($key, self::CHECKS, true) && ! in_array($key, self::VOUCHERS, true) && ! isset(self::WU[$key]);
    }

    /** All rows for the type/period — the page's DataTable does the paging, like legacy's single full list. */
    public function details(string $key, int $merchantId, ?int $userId, ?int $branchId, string $from, string $to): array
    {
        $rows = $this->exportDetails($key, $merchantId, $userId, $branchId, $from, $to);

        return [
            'columns' => $this->detailColumns($key),
            'has_receipt' => $this->hasReceipt($key),
            'data' => $rows,
            'total' => count($rows),
        ];
    }

    public function exportDetails(string $key, int $merchantId, ?int $userId, ?int $branchId, string $from, string $to): array
    {
        return $this->detailQuery($key, $merchantId, $userId, $branchId, $from, $to)->get()
            ->map(fn ($r) => $this->presentDetail($key, $r))->all();
    }

    private function detailQuery(string $key, int $merchantId, ?int $userId, ?int $branchId, string $from, string $to): Builder
    {
        [$start, $end] = $this->range($from, $to);
        $userId = ($userId ?? 0) > 0 ? $userId : null;

        if (in_array($key, self::VOUCHERS, true)) {
            [$sql, $bindings] = $this->voucherSql($key, $merchantId, $branchId, $from, $to);

            return $this->db()->query()->fromRaw("({$sql}) as t", $bindings)->select('t.*')->orderBy('t.sort_date')->orderBy('t.voucher_code');
        }

        if ($key === 'VOID_CASHOUT') {
            // Legacy drives this from ezkard_transactions (its timestamp, completed status, SUM over duplicate ledger rows)
            // and does not select exclude_fee at all.
            return $this->db()->table('ezkard_transactions as et')
                ->leftJoin('webpos_transaction as w', 'w.transaction_id', '=', 'et.transaction_id')
                ->leftJoin('merchant_terminal_users as d', 'd.id', '=', 'w.terminal_user_id')
                ->selectRaw('et.timestamp as timestamp, w.transaction_id, w.terminal_id, SUM(w.amount) as amount, w.fee_amount, w.vat_amount, w.stamp_amount, NULL as exclude_fee, d.first_name, d.last_name')
                ->where('w.merchant_id', $merchantId)
                ->whereIn('w.transaction_type', self::CASHOUT_TYPES)
                ->where('et.trans_status_id', 1)
                ->where('et.timestamp', '>=', $start)->where('et.timestamp', '<', $end)
                ->when($userId, fn ($q) => $q->where('w.terminal_user_id', $userId))
                ->when($branchId, fn ($q) => $q->where('w.branch_id', (string) $branchId))
                ->groupBy('w.transaction_id')
                ->orderByDesc(DB::raw('MAX(et.id)'));
        }

        if ($key === 'VOID_REGULAR') {
            // Legacy LEFT JOINed every ledger row of the transaction (original sale + its reversal rows) and let GROUP BY keep an
            // arbitrary one, so which timestamp showed depended on the query plan. Made deterministic: the ORIGINAL ledger row's
            // timestamp (lowest id, found via idxTransactionId); rows are still ordered by the latest ledger id, as legacy did.
            $ledger = fn (string $col, string $dir) => "(SELECT {$col} FROM ezkard_transactions e2 WHERE e2.transaction_id = w.transaction_id ORDER BY e2.id {$dir} LIMIT 1)";

            return $this->db()->table('webpos_transaction as w')
                ->leftJoin('merchant_terminal_users as d', 'd.id', '=', 'w.terminal_user_id')
                ->selectRaw('IFNULL('.$ledger('e2.timestamp', 'ASC').', w.transaction_date) as timestamp, w.transaction_id, w.terminal_id, w.amount, w.fee_amount, w.vat_amount, w.stamp_amount, w.exclude_fee, d.first_name, d.last_name')
                ->where('w.merchant_id', $merchantId)
                ->where('w.status', 1)
                ->where('w.transaction_date', '>=', $start)->where('w.transaction_date', '<', $end)
                ->whereNotIn('w.transaction_type', self::CASHOUT_TYPES)
                ->when($userId, fn ($q) => $q->where('w.terminal_user_id', $userId))
                ->when($branchId, fn ($q) => $q->where('w.branch_id', (string) $branchId))
                ->groupBy('w.transaction_id')
                ->orderByRaw($ledger('e2.id', 'DESC').' DESC');
        }

        if (isset(self::WU[$key])) {
            return $this->db()->table('wu_eod_webpos as a')
                ->leftJoin('merchant_terminal_users as d', 'd.id', '=', 'a.terminal_user_id')
                ->selectRaw("a.timestamp as timestamp, '' as transaction_id, a.terminal_id, a.amount, 0 as fee_amount, 0 as vat_amount, 0 as stamp_amount, 1 as exclude_fee, d.first_name, d.last_name")
                ->where('a.merchant_id', $merchantId)
                ->where('a.type', self::WU[$key])
                ->where('a.timestamp', '>=', $start)->where('a.timestamp', '<', $end)
                ->where('a.status', 0)
                ->when($userId, fn ($q) => $q->where('a.terminal_user_id', $userId))
                ->when($branchId, fn ($q) => $q->join('branch as e', 'e.id', '=', 'a.branch_id')->where('e.id', $branchId))
                ->orderByDesc('a.timestamp')->orderByDesc('a.id');
        }

        $q = $this->db()->table('webpos_transaction as a')
            ->leftJoin('merchant_terminal_users as d', 'd.id', '=', 'a.terminal_user_id')
            ->where('a.merchant_id', $merchantId)
            ->where('a.transaction_date', '>=', $start)->where('a.transaction_date', '<', $end)
            ->when($userId, fn ($q) => $q->where('a.terminal_user_id', $userId));

        $base = ['a.transaction_date as timestamp', 'a.transaction_id', 'a.terminal_id', 'a.amount', 'a.fee_amount', 'a.vat_amount', 'a.stamp_amount', 'a.exclude_fee', 'd.first_name', 'd.last_name'];

        if (in_array($key, self::CHECKS, true)) {
            // Both check types are the same CHECKCASHING rows in legacy; only the fee/total display differs. No status filter.
            $q->where('a.transaction_type', 'CHECKCASHING')
                ->when($branchId, fn ($q) => $q->join('branch as b', 'b.id', '=', 'a.branch_id')->where('b.id', $branchId));
        } elseif (in_array($key, ['BILLPAY', 'BILLPAY_BUSINESS'], true)) {
            // `report_by_merchant_id_for_billpay`: joins the billpay detail row, no status filter, branch on the transaction.
            $q->leftJoin('billpay_web_transactions as p', 'a.transaction_id', '=', 'p.transaction_id')
                ->where('a.transaction_type', 'BILLPAY')
                ->whereRaw($key === 'BILLPAY_BUSINESS' ? "LEFT(a.transaction_id,2) = '00'" : "LEFT(a.transaction_id,2) <> '00'")
                ->when($branchId, fn ($q) => $q->where('a.branch_id', (string) $branchId));
            $base = array_merge($base, ['p.biller_code', 'p.bill_account_no']);
        } elseif ($key === 'LOAD') {
            // `report_by_merchant_id_load`: no status filter; customer via the ledger row; one row per transaction.
            $q->leftJoin('ezkard_transactions as et', 'et.transaction_id', '=', 'a.transaction_id')
                ->leftJoin('customers as cc', 'cc.ezkard_account_id', '=', 'et.ezkard_id')
                ->where('a.transaction_type', 'LOAD')
                ->when($branchId, fn ($q) => $q->join('branch as e', 'e.id', '=', 'a.branch_id')->where('e.id', $branchId))
                ->groupBy('a.id');
            $base = array_merge($base, [DB::raw("CONCAT(MIN(cc.first_name), ' ', MIN(cc.last_name)) as customer_name"), DB::raw('MIN(cc.mobile) as customer_mobile')]);
        } else {
            $q->where('a.transaction_type', $key)
                ->where('a.status', '!=', -2)
                ->when($branchId, fn ($q) => $q->join('branch as e', 'e.id', '=', 'a.branch_id')->where('e.id', $branchId));
        }

        return $q->select($base)->orderByDesc('a.transaction_date')->orderByDesc('a.id');
    }

    private function presentDetail(string $key, object $r): array
    {
        if (in_array($key, self::VOUCHERS, true)) {
            $amount = (float) $r->amount;

            return [
                'timestamp' => $r->timestamp, 'trans_type' => self::TYPES[$key], 'voucher_code' => $r->voucher_code,
                'purchase_source' => $r->purchase_source, 'amount' => $this->money($amount), 'fee' => $this->money(0), 'total' => $this->money($amount),
                'owner' => $r->customer_name.' '.$r->mobile_number, 'status' => $r->status,
                'redeemed_source' => $r->redeemed_source, 'redeemed_date' => $r->redeemed_voided_date,
                'negative' => in_array($key, self::NEGATIVE, true), 'fee_negative' => in_array($key, self::NEGATIVE, true),
                'receipt_type' => null,
            ];
        }

        $fee = (float) $r->fee_amount;
        $f3 = $fee + (float) $r->vat_amount + (float) $r->stamp_amount;
        $raw = (float) $r->amount;
        // Legacy view: `exclude_fee == 0` — NULL counts as 0 here (unlike the summary).
        $ef = (int) $r->exclude_fee;
        $amount = $ef === 0 ? $raw - $f3 : $raw;

        $gross = match (true) {
            in_array($key, ['CASHOUT_VOUCHER', 'CASHOUT_CODE', 'CASHOUT_MOBILE', 'CASHOUT_SMARTPAY', 'BANK_DEPOSIT'], true) => $ef === 0 ? $raw : $raw + $f3,
            $key === 'GAMING_HOUSE_WITHDRAW' => $raw - $f3,
            $key === 'PURCHASE' => $raw + $fee,
            default => $raw + $f3,
        };

        $negative = in_array($key, [...self::NEGATIVE, 'GAMING_HOUSE_WITHDRAW'], true);
        $feeShown = match (true) {
            $key === 'CHECK_ON_HAND' => 0.0,
            $key === 'PURCHASE' => $fee,
            default => $f3,
        };
        $totalShown = $key === 'CHECK_ON_HAND' ? $amount : $gross;

        $row = [
            'timestamp' => $r->timestamp,
            'trans_type' => self::TYPES[$key],
            'transaction_id' => $r->transaction_id,
            'terminal_id' => $r->terminal_id,
            'amount' => $this->money($amount),
            'fee' => $this->money($feeShown),
            'total' => $this->money($totalShown),
            'user' => ($r->first_name ?? '').($r->last_name ?? ''),
            'negative' => $negative,
            'fee_negative' => $negative && $key !== 'GAMING_HOUSE_WITHDRAW',
            'receipt_type' => $this->hasReceipt($key) ? $key : null,
        ];

        if (in_array($key, ['BILLPAY', 'BILLPAY_BUSINESS'], true)) {
            $row['biller_info'] = $key === 'BILLPAY' ? $r->biller_code.'-'.$r->bill_account_no : $r->biller_code;
        }
        if ($key === 'LOAD') {
            $row['customer_name'] = (string) $r->customer_name;
            $row['customer_mobile'] = $r->customer_mobile;
        }

        return $row;
    }

    // ---------------------------------------------------------------- vouchers

    /** Legacy `get_client_id_voucher_webpos()` — merchants that run WebPOS voucher sales, fetched once per request instead of once per voucher type. */
    private function voucherWebposClients(): array
    {
        return $this->voucherWebposClients ??= $this->db()->table('webpos_transaction as w')
            ->join('clients as c', 'c.id', '=', 'w.merchant_id')
            ->whereIn('w.transaction_type', ['VOUCHER', 'CASHOUT_VOUCHER'])
            ->distinct()->pluck('w.merchant_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Legacy `get_merchant_voucher()` / `get_universal_voucher()`, ported
     * with bound parameters (legacy string-concatenated every value into the
     * SQL). Same joins, filters and per-voucher de-duplication.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function voucherSql(string $key, int $merchantId, ?int $branchId, string $from, string $to): array
    {
        $isWebpos = in_array($merchantId, $this->voucherWebposClients(), true);
        $redeemed = str_starts_with($key, 'REDEEMED_');
        $dateRange = 'v.voucher_date >= ? AND v.voucher_date < DATE_ADD(?, INTERVAL 1 DAY)';

        if (str_contains($key, 'UNIBUCKS')) {
            $where = $redeemed ? " WHERE v.status = 'REDEEMED' AND {$dateRange}" : " WHERE {$dateRange}";
            $bindings = [$from, $to];
            if ($isWebpos) {
                $where .= $redeemed
                    ? ($branchId ? " AND w.merchant_id = ? AND w.branch_id = ? AND w2.transaction_type = 'CASHOUT_VOUCHER' " : " AND w2.merchant_id = ? AND w2.transaction_type = 'CASHOUT_VOUCHER' ")
                    : ($branchId ? ' AND w.merchant_id = ? AND w.branch_id = ? ' : ' AND w.merchant_id = ? ');
                array_push($bindings, $merchantId, ...($branchId ? [(string) $branchId] : []));
            } else {
                $where .= " AND {$dateRange} AND ".($redeemed ? 'v.redeemed_client_id' : 'v.purchased_client_id').' = ? ';
                array_push($bindings, $from, $to, $merchantId);
            }

            $sql = "SELECT v.voucher_code, v.amount, receiver_mobile AS mobile_number, receiver_name AS customer_name,
                CASE WHEN v.purchased_channel='3rdParty'
                    THEN (SELECT IFNULL(CASE WHEN legal_name='' THEN dba_name ELSE legal_name END,'') FROM clients WHERE id=v.purchased_client_id)
                    ELSE v.purchased_channel END AS purchase_source,
                CASE WHEN v.redeemed_channel='3rdParty'
                    THEN (SELECT IFNULL(CASE WHEN legal_name='' THEN dba_name ELSE legal_name END,'') FROM clients WHERE id=v.redeemed_client_id)
                    ELSE v.redeemed_channel END AS redeemed_source,
                v.status, uvl.timestamp AS timestamp, v.update_date AS redeemed_voided_date, v.voucher_date AS sort_date,
                w.terminal_user_id AS user_id
                FROM universal_vouchers v
                INNER JOIN universal_vouchers_logs uvl ON (uvl.universal_vouchers_id = v.id)
                LEFT JOIN webpos_transaction w ON (w.reference = v.voucher_code AND w.transaction_type = 'VOUCHER')
                LEFT JOIN webpos_transaction w2 ON (w2.reference = v.voucher_code AND w2.transaction_type = 'CASHOUT_VOUCHER')
                {$where} AND v.purchased_channel = 'WebPOS'
                GROUP BY v.voucher_code";

            return [$sql, $bindings];
        }

        if ($redeemed) {
            $where = " WHERE v.status = 'REDEEMED' AND w2.id > 0 AND {$dateRange}";
            $bindings = [$from, $to];
            if ($isWebpos) {
                $where .= $branchId
                    ? " AND w.merchant_id = ? AND w.branch_id = ? AND w2.transaction_type = 'CASHOUT_VOUCHER' "
                    : " AND w2.merchant_id = ? AND w2.transaction_type = 'CASHOUT_VOUCHER' ";
                array_push($bindings, $merchantId, ...($branchId ? [(string) $branchId] : []));
            } else {
                $where .= ' AND v.client_record_id = ? ';
                $bindings[] = $merchantId;
            }
        } else {
            $where = " WHERE {$dateRange}";
            $bindings = [$from, $to];
            if ($isWebpos) {
                $where .= $branchId ? ' AND w.merchant_id = ? AND w.branch_id = ? ' : ' AND w.merchant_id = ? ';
                array_push($bindings, $merchantId, ...($branchId ? [(string) $branchId] : []));
            } else {
                $where .= ' AND v.merchant_owner_id = ? ';
                $bindings[] = $merchantId;
            }
        }

        $sql = "SELECT v.voucher_code, TIMESTAMP(v.voucher_date) AS timestamp,
                CASE WHEN v.customer_owner_id > 0 THEN 'CustomerApp'
                     WHEN w.id > 0 THEN 'WebPOS'
                     WHEN v.merchant_owner_id > 0 THEN c2.dba_name
                     ELSE IFNULL(c2.legal_name, IFNULL(bvg.field1, v.source)) END AS purchase_source,
                IFNULL(CONCAT(cc.first_name,' ',cc.last_name), bvg.name) AS customer_name, v.mobile AS mobile_number,
                v.amount, v.status, v.update_date AS redeemed_voided_date,
                CASE WHEN v.remarks LIKE '%CustomerApp%' THEN 'CustomerApp'
                     WHEN v.remarks LIKE '%CustomerPortal%' THEN 'CustomerPortal'
                     WHEN c.legal_name = '' THEN c.dba_name
                     WHEN w2.id > 0 THEN 'WebPOS'
                     ELSE c.legal_name END AS redeemed_source,
                v.voucher_date AS sort_date, w.terminal_user_id AS user_id
                FROM merchant_vouchers v
                LEFT JOIN clients c ON (c.id = v.client_record_id)
                LEFT JOIN clients c2 ON (c2.id = v.merchant_owner_id)
                LEFT JOIN customers cc ON (cc.mobile = v.mobile)
                LEFT JOIN batch_voucher_generation bvg ON (bvg.voucher_number = v.voucher_code)
                LEFT JOIN webpos_transaction w ON (w.reference = v.voucher_code AND w.transaction_type = 'VOUCHER')
                LEFT JOIN webpos_transaction w2 ON (w2.reference = v.voucher_code AND w2.transaction_type = 'CASHOUT_VOUCHER')
                {$where} AND w.id > 0 AND v.source = 'WebPOS'
                GROUP BY v.voucher_code";

        return [$sql, $bindings];
    }

    // ---------------------------------------------------------------- shared

    /** Branch + user scoping for the aggregate webpos query. Both keep legacy's join semantics (user must exist, branch must exist). */
    private function scopeUserBranch(Builder $q, string $alias, ?int $userId, ?int $branchId): void
    {
        if ($userId) {
            $q->where("{$alias}.terminal_user_id", $userId)
                ->join('merchant_terminal_users as d', fn ($j) => $j->on('d.id', '=', "{$alias}.terminal_user_id")->where('d.id', '=', $userId));
        }
        if ($branchId) {
            $q->join('branch as e', 'e.id', '=', "{$alias}.branch_id")->where('e.id', $branchId);
        }
    }

    /** Legacy `CAST(col AS DATE) BETWEEN from AND to`, as a sargable half-open range. */
    private function range(string $from, string $to): array
    {
        return [Carbon::parse($from)->startOfDay()->toDateTimeString(), Carbon::parse($to)->addDay()->startOfDay()->toDateTimeString()];
    }

    private function money(float $value): string
    {
        return number_format($value, 2);
    }
}
