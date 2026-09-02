<?php

namespace App\Services\Kiosk;

use App\Models\Mysuncash\Island;
use App\Models\Mysuncash\KioskTerminal;
use Illuminate\Support\Facades\DB;

/**
 * "Kiosk > Reports > Transaction" tab (legacy `fastpay::kiosk_transaction()` /
 * `getKioskTransactionReport()`). A read-only transaction listing over
 * `webpos_transaction_kiosk`, distinct from Kiosk Statement's per-terminal
 * ledger (`KioskStatementService`) — this report spans every terminal at
 * once and resolves several transaction types' "Customer / Account No"
 * column via joins Statement's ledger never needs (mobile top-up provider,
 * billpay account, Sand Dollar wallet e-mail, gift-card code, and a
 * decrypted+masked bank account number for BANK_DEPOSIT rows).
 *
 * Legacy's query is a 4-way `UNION ALL` (regular transactions, then three
 * VOUCHER sub-types split by which voucher table/product a VOUCHER-type
 * row's code resolves against) wrapped in an outer `WHERE` for the
 * type/terminal/island filters — replicated here with the same structure
 * but parameter-bound instead of string-concatenated (legacy interpolates
 * `$island_id`/`$terminal_id` directly into SQL — a real injection surface
 * not replicated). A hard-coded merchant exclusion (`merchant_id != 303`,
 * legacy's `settings::$kiosk_excluded_merchants`) applies throughout.
 *
 * Legacy's own "Total Summary" query turned out, on close reading, to have
 * no outer `GROUP BY` at all — despite selecting `trans_type`, `SUM()` with
 * no `GROUP BY` collapses it to exactly ONE grand-total row (matching the
 * view's 6-metric summary block, not a per-type breakdown). Rather than
 * reproduce that as a second, separately-structured SQL query, totals here
 * are summed in PHP from the already-fetched list rows — the exact same
 * result, guaranteed consistent with the list by construction, since
 * legacy's list and (single-row) totals query share identical filters.
 *
 * Legacy's `voucher_type()` post-processing helper (re-classifying a row
 * whose type is literally `"VOUCHER"`) is dead code given this structure:
 * every UNION branch already resolves a concrete type
 * (SUNCASH_VOUCHER/UNIBUCKS_VOUCHER/CREDIT_VOUCHER or the original
 * `wtk.transaction_type`), so `"VOUCHER"` itself never reaches PHP — not
 * ported.
 */
class KioskTransactionReportService
{
    /** legacy `settings::$kiosk_excluded_merchants` */
    private const EXCLUDED_MERCHANT_IDS = [303];

    private const PAN_KEK = 'cfbe176207b80774e8911c10893f5a0f';

    /** legacy `Fastpay::$transaction_types` — full label map, used to resolve every row's displayed "Product". */
    private const TRANSACTION_TYPE_LABELS = [
        'BPL' => 'BPL Bill Pay', 'WSC' => 'WSC Bill Pay', 'NPDCo' => 'NPDCO Bill Pay', 'ALIV' => 'Aliv Bill Pay',
        'BTC-M' => 'BTC Bill Pay', 'CB' => 'Cable Bahamas Bill Pay', 'GBPC' => 'GB Power Bill Pay', 'FIBR' => 'CBL FIBR',
        'BTC_TOPUP' => 'BTC Topup', 'ALIV_TOPUP' => 'Aliv Topup', 'KioskTopup' => 'Mobile Topup',
        'INTERNATIONAL_TOPUP' => 'International Topup', 'GAMINGHOUSE_DEPOSIT' => 'Gaming Deposit',
        'GAMINGHOUSE_WITHDRAWAL' => 'Gaming Withdrawal', 'LOCAL_GC' => 'Local Giftcard',
        'MGODIGITALSALES' => 'International Gift Cards', 'CASHOUT' => 'Cashout', 'LOAD_SANDDOLLAR' => 'Sand Dollar Load',
        'SEND_SANDDOLLAR' => 'Sand Dollar Send', 'WITHDRAW_SANDDOLLAR' => 'Sand Dollar Withdrawal',
        'GOVERNMENT_PAYMENT' => 'Government Payments', 'BUSINESS_PAYMENT' => 'Payment code Payments',
        'MONEY_TRANSFER' => 'Domestic Send Money Transfers', 'BUSINESSPAYMENT' => 'Business Account Payments',
        'BANK_DEPOSIT' => 'Bank Deposit', 'CONVENIENCE_FEE' => 'Convenience Fee',
        'LOAD_MOBILEWALLET_SANDDOLLAR' => 'Load Mobile Wallet Via Sanddollar', 'BILLPAY' => 'Billpay',
        'LOAD' => 'Suncash Deposit', 'SUNCASH_VOUCHER' => 'Suncash Voucher', 'UNIBUCKS_VOUCHER' => 'Unibucks Voucher',
        'CREDIT_VOUCHER' => 'Credit Voucher', 'VOUCHER' => 'Voucher', 'CARD_DEPOSIT' => 'Card Deposit',
        'CARD_WITHDRAWAL' => 'Card Withdrawal', 'PAYMENT_CODE' => 'Payment Code',
        'BUSINESS_BANK_DEPOSIT' => 'Business Bank Deposit', 'BUSINESS_ACCOUNT_DEPOSIT' => 'Business Account Deposit',
        'BUSINESS_ACCOUNT_WITHDRAW' => 'Business Account Withdraw', 'MONEYTRANSFER_WU' => 'Western Union', 'LOTTO' => 'Lotto',
    ];

    /** legacy `$dropdown_types`, minus BTC_TOPUP/ALIV_TOPUP/INTERNATIONAL_TOPUP (folded under "Mobile Topup" here). */
    public const PRODUCT_OPTIONS = [
        'KioskTopup' => 'Mobile Topup', 'BILLPAY' => 'Billpay', 'SUNCASH_VOUCHER' => 'Suncash Voucher',
        'UNIBUCKS_VOUCHER' => 'Unibucks Voucher', 'CREDIT_VOUCHER' => 'Credit Voucher', 'VOUCHER' => 'Voucher',
        'GAMINGHOUSE_DEPOSIT' => 'Gaming Deposit', 'GAMINGHOUSE_WITHDRAWAL' => 'Gaming Withdrawal',
        'MGODIGITALSALES' => 'International Gift Cards', 'LOAD' => 'Load', 'CASHOUT' => 'SunCash Withdrawal',
        'LOAD_SANDDOLLAR' => 'Sand Dollar Load', 'SEND_SANDDOLLAR' => 'Sand Dollar Send',
        'WITHDRAW_SANDDOLLAR' => 'Sand Dollar Withdrawal', 'GOVERNMENT_PAYMENT' => 'Government Payments',
        'BUSINESS_PAYMENT' => 'Payment code Payments', 'MONEY_TRANSFER' => 'Domestic Send Money Transfers',
        'BUSINESSPAYMENT' => 'Business Account Payments', 'BANK_DEPOSIT' => 'Bank Deposit',
        'CONVENIENCE_FEE' => 'Convenience Fee', 'LOAD_MOBILEWALLET_SANDDOLLAR' => 'Load Mobile Wallet Via Sanddollar',
        'LOCAL_GC' => 'Local Giftcard', 'CARD_DEPOSIT' => 'Card Deposit', 'CARD_WITHDRAWAL' => 'Card Withdrawal',
        'BUSINESS_BANK_DEPOSIT' => 'Business Bank Deposit', 'BUSINESS_ACCOUNT_DEPOSIT' => 'Business Account Deposit',
        'BUSINESS_ACCOUNT_WITHDRAW' => 'Business Account Withdraw', 'MONEYTRANSFER_WU' => 'Western Union', 'LOTTO' => 'Lotto',
    ];

    private ?string $panKeyCache = null;

    public function listTerminals(): array
    {
        // Legacy applies no status filter for this report's Terminal dropdown, unlike every other Kiosk report.
        return KioskTerminal::orderBy('name')->get(['id', 'name'])->all();
    }

    public function listIslands(): array
    {
        return Island::orderBy('name')->get(['id', 'code', 'name'])->all();
    }

    private function hideItDecrypt(string $pass, string $encrypted, string $iv): ?string
    {
        $result = openssl_decrypt($encrypted, 'aes-256-cbc', $pass, false, substr(sha1($iv), 3, 16));

        return $result === false ? null : $result;
    }

    private function panKey(): string
    {
        if ($this->panKeyCache !== null) {
            return $this->panKeyCache;
        }

        $dekEnc = DB::connection('mysuncash')->table('keys')->orderByDesc('timestamp')->value('key');
        $this->panKeyCache = $dekEnc ? (string) $this->hideItDecrypt(self::PAN_KEK, $dekEnc, sha1('aes-256-cbc')) : '';

        return $this->panKeyCache;
    }

    /** Decrypts+masks a BANK_DEPOSIT row's linked kiosk bank account number to its last 4 digits, matching legacy exactly. */
    private function maskedBankAccountNo(string $customerNumber): string
    {
        $encrypted = DB::connection('mysuncash')->table('kiosk_bank_accounts')
            ->where('customer_number', $customerNumber)
            ->value('account_number');

        if (! $encrypted) {
            return '000000000';
        }

        $decrypted = $this->hideItDecrypt($this->panKey(), $encrypted, sha1(md5($customerNumber)));
        if (! $decrypted) {
            return '000000000';
        }

        $len = strlen($decrypted);

        return $len <= 4 ? $decrypted : str_repeat('0', $len - 4).substr($decrypted, -4);
    }

    private function unionBranches(): string
    {
        $excluded = implode(',', self::EXCLUDED_MERCHANT_IDS);

        return <<<SQL
            SELECT
                wtk.transaction_date AS `datetime`,
                CASE
                    WHEN wtk.transaction_type = 'BILLPAY' THEN bt.biller_code
                    WHEN wtk.transaction_type = 'KioskTopup' AND mtt.provider = 'aliv' THEN 'ALIV_TOPUP'
                    WHEN wtk.transaction_type = 'KioskTopup' AND mtt.provider = 'emida' THEN 'BTC_TOPUP'
                    WHEN wtk.transaction_type = 'KioskTopup' AND mtt.provider = 'paynation' THEN 'INTERNATIONAL_TOPUP'
                    ELSE wtk.transaction_type
                END AS transaction_type,
                CASE
                    WHEN wtk.transaction_type = 'KioskTopup' AND mtt.provider = 'aliv' THEN 'ALIV_TOPUP'
                    WHEN wtk.transaction_type = 'KioskTopup' AND mtt.provider = 'emida' THEN 'BTC_TOPUP'
                    WHEN wtk.transaction_type = 'KioskTopup' AND mtt.provider = 'paynation' THEN 'INTERNATIONAL_TOPUP'
                    ELSE wtk.transaction_type
                END AS trans_type,
                wtk.transaction_id AS transaction_id,
                CASE
                    WHEN wtk.transaction_type = 'KioskTopup' THEN mtt.mobile_number
                    WHEN wtk.transaction_type = 'BILLPAY' THEN bt.bill_account_no
                    WHEN wtk.transaction_type IN ('GOVERNMENT_PAYMENT','MONEY_TRANSFER','WITHDRAWAL_SANDDOLLAR','BANK_DEPOSIT','BUSINESSPAYMENT','LOCAL_GC','BUSINESS_BANK_DEPOSIT','BUSINESS_BANK_WITHDRAWAL','BUSINESS_ACCOUNT_DEPOSIT')
                        THEN wtk.trans_ref_id
                    WHEN wtk.transaction_type IN ('CASHOUT','LOAD','MONEYTRANSFER_WU') THEN c.mobile
                    WHEN wtk.transaction_type IN ('SEND_SANDDOLLAR','LOAD_MOBILEWALLET_SANDDOLLAR','LOAD_SANDDOLLAR')
                        THEN IF(LOCATE('@sanddollar.bs', st.customer) > 0, REPLACE(st.customer, ' ', '+'), CONCAT(REPLACE(st.customer, ' ', '+'), '@sanddollar.bs'))
                    WHEN wtk.transaction_type = 'MGODIGITALSALES' THEN mgo.giftcard_code
                    WHEN wtk.transaction_type IN ('GAMINGHOUSE_WITHDRAWAL','GAMINGHOUSE_DEPOSIT','CARD_DEPOSIT','CARD_WITHDRAWAL') THEN wtk.reference_id
                    ELSE ''
                END AS customer_number,
                wtk.amount, wtk.fee_amount, wtk.vat_amount, (wtk.fee_amount + wtk.vat_amount) AS total_fees,
                wtk.total_amount, wtk.terminal_id, ktl.code AS terminal_code, ktl.island, isl.name AS island_name, ktl.location
            FROM webpos_transaction_kiosk wtk
            LEFT JOIN customers c ON c.id = wtk.customer_id AND wtk.customer_id != '-1'
            INNER JOIN clients cl ON cl.id = wtk.merchant_id
            LEFT JOIN mobile_topup_transactions mtt ON mtt.id = wtk.reference_id AND wtk.transaction_type = 'KioskTopup'
            LEFT JOIN billpay_transactions bt ON bt.settlement_transaction_id = wtk.transaction_id AND wtk.transaction_type = 'BILLPAY'
            LEFT JOIN sanddollar_trail st ON st.transaction_id = wtk.transaction_id AND wtk.transaction_type IN ('SEND_SANDDOLLAR','LOAD_MOBILEWALLET_SANDDOLLAR','SANDDOLLAR_LOAD')
            LEFT JOIN mgo_giftcard_transactions mgo ON mgo.transaction_id = wtk.id AND wtk.transaction_type = 'MGODIGITALSALES'
            INNER JOIN kiosk_terminal ktl ON ktl.id = wtk.terminal_id
            LEFT JOIN island isl ON isl.id = ktl.island
            WHERE wtk.transaction_date >= ? AND wtk.transaction_date <= ?
                AND wtk.merchant_id NOT IN ({$excluded})
                AND wtk.status = 0
                AND wtk.transaction_type NOT IN ('VOUCHER')

            UNION ALL

            SELECT
                wtk.transaction_date AS `datetime`, 'SUNCASH_VOUCHER' AS transaction_type, 'SUNCASH_VOUCHER' AS trans_type,
                wtk.transaction_id AS transaction_id, wtk.trans_ref_id AS customer_number,
                wtk.amount, wtk.fee_amount, wtk.vat_amount, (wtk.fee_amount + wtk.vat_amount) AS total_fees,
                wtk.total_amount, wtk.terminal_id, ktl.code AS terminal_code, ktl.island, isl.name AS island_name, ktl.location
            FROM webpos_transaction_kiosk wtk
            INNER JOIN clients cl ON cl.id = wtk.merchant_id
            INNER JOIN merchant_vouchers mv ON mv.voucher_code = wtk.trans_ref_id AND wtk.transaction_type = 'VOUCHER'
            INNER JOIN kiosk_terminal ktl ON ktl.id = wtk.terminal_id
            LEFT JOIN island isl ON isl.id = ktl.island
            WHERE wtk.status = 0 AND wtk.transaction_type = 'VOUCHER'
                AND wtk.transaction_date >= ? AND wtk.transaction_date <= ?
                AND (wtk.trans_ref_id != '' AND wtk.trans_ref_id != '-1')
                AND wtk.merchant_id NOT IN ({$excluded})

            UNION ALL

            SELECT
                wtk.transaction_date AS `datetime`, 'UNIBUCKS_VOUCHER' AS transaction_type, 'UNIBUCKS_VOUCHER' AS trans_type,
                wtk.transaction_id AS transaction_id, wtk.trans_ref_id AS customer_number,
                wtk.amount, wtk.fee_amount, wtk.vat_amount, (wtk.fee_amount + wtk.vat_amount) AS total_fees,
                wtk.total_amount, wtk.terminal_id, ktl.code AS terminal_code, ktl.island, isl.name AS island_name, ktl.location
            FROM webpos_transaction_kiosk wtk
            INNER JOIN clients cl ON cl.id = wtk.merchant_id
            INNER JOIN universal_vouchers mv ON mv.voucher_code = wtk.trans_ref_id AND wtk.transaction_type = 'VOUCHER'
            INNER JOIN kiosk_terminal ktl ON ktl.id = wtk.terminal_id
            LEFT JOIN island isl ON isl.id = ktl.island
            WHERE wtk.status = 0 AND mv.voucher_product_id != 3 AND wtk.transaction_type = 'VOUCHER'
                AND wtk.transaction_date >= ? AND wtk.transaction_date <= ?
                AND (wtk.trans_ref_id != '' AND wtk.trans_ref_id != '-1')
                AND wtk.merchant_id NOT IN ({$excluded})

            UNION ALL

            SELECT
                wtk.transaction_date AS `datetime`, 'CREDIT_VOUCHER' AS transaction_type, 'CREDIT_VOUCHER' AS trans_type,
                wtk.transaction_id AS transaction_id, wtk.trans_ref_id AS customer_number,
                wtk.amount, wtk.fee_amount, wtk.vat_amount, (wtk.fee_amount + wtk.vat_amount) AS total_fees,
                wtk.total_amount, wtk.terminal_id, ktl.code AS terminal_code, ktl.island, isl.name AS island_name, ktl.location
            FROM webpos_transaction_kiosk wtk
            INNER JOIN clients cl ON cl.id = wtk.merchant_id
            INNER JOIN universal_vouchers mv ON mv.voucher_code = wtk.trans_ref_id
            INNER JOIN kiosk_terminal ktl ON ktl.id = wtk.terminal_id
            LEFT JOIN island isl ON isl.id = ktl.island
            WHERE wtk.status = 0 AND wtk.transaction_type = 'VOUCHER' AND mv.voucher_product_id = 3
                AND wtk.transaction_date >= ? AND wtk.transaction_date <= ?
                AND (wtk.trans_ref_id != '' AND wtk.trans_ref_id != '-1' AND wtk.trans_ref_id LIKE '7%')
                AND wtk.merchant_id NOT IN ({$excluded})
            SQL;
    }

    public function list(string $dateFrom, string $dateTo, ?string $type = null, ?int $terminalId = null, ?int $islandId = null): array
    {
        $dateFromTs = "{$dateFrom} 00:00:00";
        $dateToTs = "{$dateTo} 23:59:59";
        $bindings = array_merge(...array_fill(0, 4, [$dateFromTs, $dateToTs]));

        $outerWhere = [];
        if ($type) {
            if ($type === 'KioskTopup') {
                $outerWhere[] = 'trans_type IN (?, ?, ?)';
                array_push($bindings, 'BTC_TOPUP', 'ALIV_TOPUP', 'INTERNATIONAL_TOPUP');
            } else {
                $outerWhere[] = 'trans_type = ?';
                $bindings[] = $type;
            }
        }
        if ($terminalId) {
            $outerWhere[] = 'terminal_id = ?';
            $bindings[] = $terminalId;
        }
        if ($islandId) {
            $outerWhere[] = 'island = ?';
            $bindings[] = $islandId;
        }
        $outerWhereSql = $outerWhere ? 'WHERE '.implode(' AND ', $outerWhere) : '';

        $sql = 'SELECT * FROM ('.$this->unionBranches().") AS sql_all\n{$outerWhereSql}\nORDER BY `datetime` DESC";

        $rows = DB::connection('mysuncash')->select($sql, $bindings);

        $presented = array_map(function ($row) {
            return [
                'datetime' => $row->datetime,
                'transaction_type' => $row->transaction_type,
                'product' => self::TRANSACTION_TYPE_LABELS[$row->transaction_type] ?? $row->transaction_type,
                'transaction_id' => $row->transaction_id,
                'customer_number' => $row->transaction_type === 'BANK_DEPOSIT'
                    ? $this->maskedBankAccountNo((string) $row->customer_number)
                    : $row->customer_number,
                'amount' => (float) $row->amount,
                'fee_amount' => (float) $row->fee_amount,
                'vat_amount' => (float) $row->vat_amount,
                'total_fees' => (float) $row->total_fees,
                'total_amount' => (float) $row->total_amount,
                'terminal_id' => (int) $row->terminal_id,
                'terminal_code' => $row->terminal_code,
                'island_id' => $row->island,
                'island' => $row->island_name,
                'location' => $row->location,
            ];
        }, $rows);

        $totals = [
            'transaction_count' => count($presented),
            'total_cash_received' => 0.0,
            'total_product_amount' => 0.0,
            'total_vat' => 0.0,
            'total_fees' => 0.0,
            'grand_total_fees' => 0.0,
        ];
        foreach ($presented as $row) {
            $totals['total_cash_received'] += $row['total_amount'];
            $totals['total_product_amount'] += $row['amount'];
            $totals['total_vat'] += $row['vat_amount'];
            $totals['total_fees'] += $row['fee_amount'];
            $totals['grand_total_fees'] += $row['total_fees'];
        }

        return ['rows' => $presented, 'totals' => $totals];
    }
}
