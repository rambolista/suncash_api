<?php

namespace App\Services\Reports;

use App\Services\Reports\Concerns\InclusiveDateRange;
use Illuminate\Support\Facades\DB;

/**
 * "Reports > Users / Client Management" (legacy `user_reports` controller +
 * `user_report_model`): one page with a "List of Reports" dropdown. The seven
 * reports the dropdown offers for this menu (lookup `report_type`, code
 * `userclient`) each become a tab here, in the same columns legacy rendered.
 * (Legacy's lookup also lists "Suspicious Activity" and "Closed Account", but
 * under a different category code — they never appear in this menu's
 * dropdown, and had no backend behind them.)
 *
 * Output rows/columns match legacy; HOW they're read changed:
 *  - Queries select only the columns shown. Legacy fetched `customers.*`
 *    (base64 images included) for the balance/KYC lists, and `SELECT *`
 *    elsewhere.
 *  - Joins that can't change the result and whose columns aren't shown are
 *    dropped (island, id_type, clients on deposits).
 *  - `CAST(timestamp AS DATE) BETWEEN` on `ezkard_transactions` -> a range on
 *    the (VARCHAR) timestamp, backed by a (trans_type_id, timestamp) index.
 *    That column holds 59 junk values ("1", epoch seconds) which CAST turns
 *    into NULL; `tsGuard()` keeps them out exactly as before.
 *
 * Deliberate differences from legacy (bugs, not rules):
 *  - Users Profile, Change Pin History and Money Transfer filtered
 *    `col <= 'YYYY-MM-DD'` against a timestamp, i.e. up to 00:00 of the To
 *    day — so a same-day range (their own default) matched nothing. The To
 *    day is included, as in the Deposits/Withdrawals reports.
 *  - Users Profile's "Transaction" link passed the customer's *id* where the
 *    history query expects a *mobile number*, so it always came back empty. It
 *    now lists the customer's transactions.
 *
 * Kept as legacy: the amount thresholds (`CAST(amount AS DECIMAL) > n` rounds
 * to whole units) and Expired KYC's "expired before To" rule.
 */
class UserClientReportService
{
    use InclusiveDateRange;

    public const MODULE_PATH = '/reports/user-client-management';

    public const TABS = [
        'user_profile' => 'Users Profile',
        'user_balance' => 'User Balance',
        'change_pin' => 'Change Pin History',
        'deposits' => 'Significant Deposits',
        'withdrawals' => 'Significant Withdrawals',
        'money_transfer' => 'Money Transfer',
        'expired_kyc' => 'Expired KYC',
    ];

    /** Legacy ezkard_transactions.trans_type_id list that counts as a withdrawal. */
    private const WITHDRAWAL_TYPES = [1, 5, 7, 9, 11, 18, 20, 26, 32, 24, 35, 41, 45, 49];

    /** Column sets (also the export columns, minus `image`/`action` types). */
    public const COLUMNS = [
        'user_profile' => [
            ['key' => 'name', 'label' => 'Name'], ['key' => 'type', 'label' => 'Type'],
            ['key' => 'created', 'label' => 'Date Account Created'], ['key' => 'upgraded', 'label' => 'Date Upgraded'],
            ['key' => 'activated_by', 'label' => 'Rep who Activated'], ['key' => 'balance', 'label' => 'Balance'],
            ['key' => 'picture', 'label' => 'Picture', 'type' => 'image'], ['key' => 'signature', 'label' => 'Signature', 'type' => 'image'],
            ['key' => 'scanned_id', 'label' => 'Scanned ID', 'type' => 'image'],
        ],
        'user_balance' => [['key' => 'name', 'label' => 'Name'], ['key' => 'balance', 'label' => 'Balance']],
        'change_pin' => [['key' => 'mobile', 'label' => 'Mobile No.'], ['key' => 'date_modified', 'label' => 'Date Modified']],
        'deposits' => [
            ['key' => 'transaction_id', 'label' => 'Transaction ID'], ['key' => 'name', 'label' => 'Name'], ['key' => 'description', 'label' => 'Description'],
            ['key' => 'amount', 'label' => 'Amount'], ['key' => 'date', 'label' => 'Date'],
        ],
        'withdrawals' => [
            ['key' => 'transaction_id', 'label' => 'Transaction ID'], ['key' => 'name', 'label' => 'Name'], ['key' => 'description', 'label' => 'Description'],
            ['key' => 'amount', 'label' => 'Amount'], ['key' => 'date', 'label' => 'Date'],
        ],
        'money_transfer' => [
            ['key' => 'originating', 'label' => 'Originating Account'], ['key' => 'originating_location', 'label' => 'Location'],
            ['key' => 'destination', 'label' => 'Destination Account'], ['key' => 'destination_location', 'label' => 'Location'],
            ['key' => 'amount', 'label' => 'Amount'], ['key' => 'fee', 'label' => 'Fee'], ['key' => 'date', 'label' => 'Date'],
        ],
        'expired_kyc' => [
            ['key' => 'name', 'label' => 'Name'], ['key' => 'mobile', 'label' => 'Mobile No.'], ['key' => 'id_type', 'label' => 'ID Card Type'],
            ['key' => 'id_number', 'label' => 'ID Card Number'], ['key' => 'created', 'label' => 'Date Account Created'], ['key' => 'expiry', 'label' => 'Date Expiry'],
        ],
    ];

    private function db()
    {
        return DB::connection('mysuncash');
    }

    /** Legacy "--Select Amount--" options (lookup `value`): submitted value is `set_name`, shown text `description`. */
    public function amountOptions(): array
    {
        return $this->db()->table('value_sets_lookup')->where('set_type', 'value')->orderByDesc('id')
            ->get(['set_name', 'description'])->map(fn ($r) => ['value' => $r->set_name, 'label' => $r->description])->all();
    }

    public function exportColumns(string $tab): array
    {
        return array_values(array_filter(self::COLUMNS[$tab], fn ($c) => ($c['type'] ?? 'text') === 'text'));
    }

    public function list(string $tab, ?string $from, ?string $to, ?string $amount, bool $applied = false): array
    {
        return match ($tab) {
            'user_profile' => $this->userProfile($from, $to),
            'user_balance' => $this->userBalance($amount),
            'change_pin' => $this->changePin($from, $to),
            'deposits' => $this->transactions(false, $from, $to, $amount),
            'withdrawals' => $this->transactions(true, $from, $to, $amount),
            'money_transfer' => $this->moneyTransfer($from, $to),
            'expired_kyc' => $this->expiredKyc($from, $to, $applied),
        };
    }

    private function userProfile(?string $from, ?string $to): array
    {
        $q = $this->db()->table('customers as c')
            ->join('ezkard_accounts as a', 'a.id', '=', 'c.ezkard_account_id')
            ->select(['c.id', 'c.first_name', 'c.middle_name', 'c.last_name', 'c.customer_access', 'c.create_on', 'c.updated_on', 'c.created_by', 'a.card_balance', 'c.image_url', 'c.signature', 'c.scanned_id'])
            ->orderByDesc('c.create_on')->orderByDesc('c.id');
        if ($r = $this->range($from, $to)) {
            $q->where('c.create_on', '>=', $r[0])->where('c.create_on', '<=', $r[1]);
        }

        return $q->get()->map(fn ($c) => [
            'id' => (int) $c->id,
            'name' => $c->first_name.' '.$c->middle_name.' '.$c->last_name,
            'type' => $c->customer_access,
            'created' => $c->create_on,
            'upgraded' => $c->updated_on,
            'activated_by' => $c->created_by,
            'balance' => number_format((float) $c->card_balance, 2),
            'picture' => filled($c->image_url) ? $c->image_url : null,
            'signature' => $this->imageSrc($c->signature),
            'scanned_id' => $this->imageSrc($c->scanned_id),
        ])->all();
    }

    /** Legacy: http(s) URL as is; an existing data: URL with whitespace -> '+'; bare base64 gets a jpg data-URL prefix. */
    private function imageSrc(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }
        if (str_starts_with($value, 'http')) {
            return $value;
        }
        $clean = preg_replace('/\s/', '+', $value);

        return str_starts_with($value, 'data') ? $clean : 'data:image/jpg;base64,'.$clean;
    }

    private function userBalance(?string $amount): array
    {
        $q = $this->db()->table('customers as c')
            ->join('ezkard_accounts as a', 'a.id', '=', 'c.ezkard_account_id')
            ->select(['c.first_name', 'c.last_name', 'a.card_balance'])
            ->orderBy('c.id');
        if (filled($amount)) {
            $q->whereRaw('CAST(a.card_balance AS DECIMAL) > ?', [$amount]);
        }

        return $q->get()->map(fn ($r) => ['name' => $r->first_name.' '.$r->last_name, 'balance' => number_format((float) $r->card_balance, 2)])->all();
    }

    private function changePin(?string $from, ?string $to): array
    {
        $q = $this->db()->table('ezkard_accounts as a')
            ->join('ezkard_pins as p', 'p.ezkard_id', '=', 'a.id')
            ->whereNotNull('p.date_modified')
            ->select(['a.mobile_number', 'p.date_modified'])
            ->orderBy('p.id');
        if ($r = $this->range($from, $to)) {
            $q->where('p.date_modified', '>=', $r[0])->where('p.date_modified', '<=', $r[1]);
        }

        return $q->get()->map(fn ($r) => ['mobile' => $r->mobile_number, 'date_modified' => $r->date_modified])->all();
    }

    private function transactions(bool $withdrawals, ?string $from, ?string $to, ?string $amount): array
    {
        $q = $this->db()->table('ezkard_transactions as e')
            ->join('ezkard_accounts as a', 'a.id', '=', 'e.ezkard_id')
            ->join('customers as cu', 'cu.mobile', '=', 'a.mobile_number')
            ->select(['e.transaction_id', 'e.description', 'e.amount', 'e.timestamp', 'cu.first_name', 'cu.last_name'])
            ->orderByDesc('e.timestamp');

        if ($withdrawals) {
            // Legacy INNER-joined clients for withdrawals (the merchant must exist) and LEFT-joined it for deposits (no effect, unused).
            $q->join('clients as cl', 'cl.id', '=', 'e.merchant_id')->whereIn('e.trans_type_id', self::WITHDRAWAL_TYPES);
        } else {
            $q->where('e.trans_type_id', 0);
        }
        if ($r = $this->range($from, $to)) {
            $this->tsGuard($q);
            $q->where('e.timestamp', '>=', $r[0])->where('e.timestamp', '<=', $r[1]);
        }
        if (filled($amount)) {
            $q->whereRaw('CAST(e.amount AS DECIMAL) > ?', [$amount]);
        }

        return $q->get()->map(fn ($r) => [
            'transaction_id' => $r->transaction_id,
            'name' => $r->first_name.' '.$r->last_name,
            'description' => $r->description,
            'amount' => $r->amount.' BSD',
            'date' => $r->timestamp,
        ])->all();
    }

    private function moneyTransfer(?string $from, ?string $to): array
    {
        $q = $this->db()->table('cashout_transactionsv3 as t')
            ->join('transaction_status as s', 's.id', '=', 't.status')
            ->join('cashout_transaction_detailsv3 as d', 'd.cashout_id', '=', 't.id')
            ->select(['d.sender_fname', 'd.sender_lname', 'd.sender_location', 'd.bene_fname', 'd.bene_lname', 'd.bene_location', 't.amount', 't.currency', 't.fee_amount', 't.date_requested'])
            ->orderBy('t.date_requested')->orderBy('t.id');
        if ($r = $this->range($from, $to)) {
            $q->where('t.date_requested', '>=', $r[0])->where('t.date_requested', '<=', $r[1]);
        }

        return $q->get()->map(fn ($r) => [
            'originating' => $r->sender_fname.' '.$r->sender_lname,
            'originating_location' => $r->sender_location,
            'destination' => $r->bene_fname.' '.$r->bene_lname,
            'destination_location' => $r->bene_location,
            'amount' => $r->amount.' '.$r->currency,
            'fee' => $r->fee_amount,
            'date' => $r->date_requested,
        ])->all();
    }

    /** `id_card_expiry` is "MM/YYYY"; a card is expired once the first of that month is before To (default today). */
    private function expiredKyc(?string $from, ?string $to, bool $applied): array
    {
        $to = filled($to) ? $to : now()->toDateString();
        $expiry = "CAST(CONCAT(SUBSTR(c.id_card_expiry,4,6),'-',SUBSTR(c.id_card_expiry,1,2),'-01') AS DATE)";

        $q = $this->db()->table('customers as c')
            ->join('ezkard_accounts as a', 'a.id', '=', 'c.ezkard_account_id')
            ->leftJoin('id_type as t', 't.code', '=', 'c.id_card_type')
            ->where('c.id_card_expiry', '<>', '')
            ->whereRaw("{$expiry} < ?", [$to])
            ->select(['c.first_name', 'c.last_name', 'c.mobile', 't.description as id_description', 'c.id_card_num', 'c.create_on', 'c.id_card_expiry'])
            ->orderByDesc('c.create_on')->orderByDesc('c.id');
        // Legacy: the page's first load had no lower bound, but "Apply Filters" with a blank From defaulted it to 1970-01-01
        // (which also drops the ~130 customers whose expiry is an invalid/zero date).
        if (filled($from) || $applied) {
            $q->whereRaw("{$expiry} >= ?", [filled($from) ? $from : '1970-01-01']);
        }

        return $q->get()->map(fn ($r) => [
            'name' => $r->first_name.' '.$r->last_name,
            'mobile' => $r->mobile,
            'id_type' => $r->id_description,
            'id_number' => $r->id_card_num,
            'created' => $r->create_on,
            'expiry' => $r->id_card_expiry,
        ])->all();
    }

    /** Users Profile > Transaction: the customer's ledger (legacy `gettranshistory`, keyed by mobile number). */
    public function customerTransactions(int $customerId): ?array
    {
        $mobile = $this->db()->table('customers')->where('id', $customerId)->value('mobile');
        if ($mobile === null) {
            return null;
        }

        return $this->db()->table('ezkard_transactions as e')
            ->join('ezkard_accounts as a', 'a.id', '=', 'e.ezkard_id')
            ->leftJoin('clients as c', 'c.id', '=', 'e.merchant_id')
            ->where('a.mobile_number', $mobile)
            ->orderByDesc('e.timestamp')
            ->get(['e.transaction_id', 'e.description', 'c.merchant_name', 'e.amount', 'e.timestamp'])
            ->map(fn ($r) => ['transaction_id' => $r->transaction_id, 'description' => $r->description, 'cashier' => $r->merchant_name, 'amount' => $r->amount, 'date' => $r->timestamp])
            ->all();
    }
}
