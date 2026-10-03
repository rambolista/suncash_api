<?php

namespace App\Services\Reports\Auditor;

/**
 * The Auditor's Report types (legacy `auditors_reports` controller + model): one entry per option of the "Report Type"
 * dropdown, in legacy order.
 *
 *   label    — the dropdown text, verbatim ("*" marks the later additions, as in legacy)
 *   dates    — which filters apply: 'range' (Start + End Date), 'end' (End Date only — an "as of" list), 'none' (a snapshot),
 *              'merchant' (Merchant + Start + End Date)
 *   columns  — result alias => export header, in the legacy order. Legacy's SQL aliases are kept as they are: MySQL resolves a
 *              bare GROUP BY / ORDER BY name to a table column before a SELECT alias, so renaming an alias can change a grouping.
 *   sql      — the legacy query, with `{from}` / `{to}` / `{merchant}` / `{fix}` / `{voucher_client}` bound as parameters (legacy
 *              pasted the posted text straight into the SQL). Date windows legacy wrote as DATE(col) / CAST(col AS DATE) — which
 *              no index can serve — are `{day col}`, `{day_ge col}`, `{day_le col}`, `{day_lt col}` / `{day_lt_next col}`; they
 *              expand to the same window as a plain range on `col` (see AuditorReportService::compile()); `{to_next}` is the day
 *              after End Date.
 *   note    — where this port deliberately differs from legacy (shown under the report type)
 *
 * Row caps (LIMIT) and each query's own end-of-range rule are legacy's: some include the whole End Date, some stop at its midnight.
 */
final class AuditorReportCatalog
{
    /** Legacy `GAMING_DEPOSIT_DATE_FIX` — before this, Gaming Funds fees were recorded in `customer_transaction_histories`, not the ledger. */
    public const GAMING_DEPOSIT_DATE_FIX = '2022-08-09 08:47:06';

    /** Ledger description groups the customer reports collapse to (legacy CASE, in order). */
    private const DESCRIPTION_GROUPS = [
        'Customers Payment%' => 'Customers Payment', 'Bill Pay%' => 'Bill Pay', 'Business Billpay%' => 'Business Billpay',
        'Bank deposit - Credit%' => 'Bank deposit - Credit', 'Bank withdrawal -Credit%' => 'Bank withdrawal -Credit',
        'Bank withdrawal -Debit%' => 'Bank withdrawal -Debit', 'Bank withdrawal -Fee%' => 'Bank withdrawal -Fee',
        'Business Payment%' => 'Business Payment', 'Gaming funds account deposit%' => 'Gaming funds account deposit',
        'Gaming funds account withdrawal%' => 'Gaming funds account withdrawal', 'Load SandDollar%' => 'Load SandDollar',
        'Mobile Topup%' => 'Mobile Topup', 'New Online Purchase%' => 'New Online Purchase', 'Payment Via SandDollar%' => 'Payment Via SandDollar',
        'Request Funds%' => 'Request Funds', 'Sale/Purchase%' => 'Sale/Purchase', 'Send SandDollar%' => 'Send SandDollar',
        'SunCash Voucher Debit%' => 'SunCash Voucher Debit', 'UniBucks Voucher Debit%' => 'UniBucks Voucher Debit', 'WU%' => 'WU',
        'Send P2P from%' => 'Receive P2P', 'Send P2P to%' => 'Send P2P', 'Cash Investment Credit%' => 'Cash Investment Credit',
        'Send Money - Fee%' => 'Send Money - Fee', 'Send Money to%' => 'Send Money to',
        'Westernunion Money Transfer (SEND) MTCN:%' => 'Westernunion Money Transfer (SEND)', 'Send Gift Card to%' => 'Send Gift Card',
        'Received money transfer from%' => 'Received money transfer',
    ];

    private const WEBPOS_COLUMNS = [
        'transaction_date' => 'Transaction Date', 'transaction_id' => 'Transaction Id', 'transaction_type' => 'Transaction Type',
        'merchant_id' => 'Merchant Id', 'branch_id' => 'Branch Id', 'terminal_user_id' => 'Terminal User Id', 'terminal_id' => 'Terminal Id',
        'amount' => 'Amount', 'fee_amount' => 'Fee Amount', 'vat_amount' => 'Vat Amount', 'stamp_amount' => 'Stamp Amount',
        'total_amount' => 'Total Amount', 'brand_name' => 'Brand Name', 'model' => 'Model', 'terminal_type' => 'Terminal Type',
        'user_name' => 'User Name', 'user_fullname' => 'User FullName', 'first_name' => 'First Name', 'last_name' => 'Last Name',
        'branch_code' => 'Branch Code', 'branch_description' => 'Branch Description', 'merchant_code' => 'Merchant Code', 'merchant_name' => 'Merchant Name',
    ];

    private const VOUCHER_COLUMNS = [
        'VOUCHER_DATE' => 'Transaction Date', 'VOUCHER_NUMBER' => 'Voucher Number', 'AMOUNT' => 'Amount', 'MOBILE_NUMBER' => 'Mobile Number',
        'EMAIL_ADDRESS' => 'Email Address', 'PURCHASE_SOURCE' => 'Purchase Source', 'REDEEMED_SOURCE' => 'Redeemed Source',
        'STATUS' => 'Status', 'REDEEMED_VOIDED_DATE' => 'Redeemed/Voided Date',
    ];

    private const CARD_PAYMENT_COLUMNS = [
        'TransactionDate' => 'Transaction Date', 'TransactionType' => 'Transaction Type', 'BusinessName' => 'Business Name', 'OrderID' => 'Order ID',
        'ReferenceNumber' => 'Reference Number', 'CardHolderName' => 'CardHolder Name', 'CardNumber' => 'Card Number',
        'TotalAmountPaid' => 'Total Amount Paid', 'TotalCreditToMerchant' => 'Total Amount To Merchant',
    ];

    private const CREDITS_COLUMNS = [
        'id' => 'id', 'Merchant_BusinessName' => 'Merchant/BusinessName', 'CustomerName' => 'Customer Name', 'CustomerMobile' => 'Customer Mobile',
        'CustomerDescription' => 'Customer Description', 'Amount' => 'Amount', 'Transaction_date' => 'Transaction Date',
    ];

    private const MANUAL_SETTLEMENT_COLUMNS = [
        'Merchant_BusinessName' => 'Merchant/BusinessName', 'TransactionType' => 'Transaction Type', 'Bank' => 'Bank', 'BankBranch' => 'Bank Branch',
    ];

    /** The merchant name rule shared by the business reports. */
    private const BUSINESS_NAME = "CASE WHEN c.merchant_name = '' THEN c.dba_name WHEN c.dba_name = '' THEN c.legal_name ELSE c.merchant_name END";

    /** Legacy's `CASE WHEN '2014-01'` quirk (a bare string is truthy) is kept: every row that isn't Kiosk reads WebPOS. */
    private const BILLPAY_SOURCE = "CASE WHEN client_id = 'quickpay' THEN 'KIOSK' WHEN '2014-01' THEN 'WebPOS'
        WHEN (SELECT client_id FROM clients WHERE client_id LIKE 'SC%' AND id = c.id) = c.client_id THEN 'WebPOS' ELSE client_id END";

    private static function descriptionCase(): string
    {
        $when = '';
        foreach (self::DESCRIPTION_GROUPS as $like => $label) {
            $when .= " WHEN ezt.description LIKE '{$like}' THEN '{$label}'";
        }

        return "CASE{$when} ELSE ezt.description END";
    }

    /** webpos_transaction with its terminal / user / branch / merchant details (23 columns). */
    private static function webposWide(string $where): string
    {
        return "SELECT a.transaction_date, a.transaction_id, a.transaction_type, a.merchant_id, a.branch_id, a.terminal_user_id, a.terminal_id, a.amount,
            (a.fee_amount + a.vat_amount + a.stamp_amount) AS fee_amount, a.vat_amount, a.stamp_amount, a.total_amount,
            b.brand_name, b.model, c.type AS terminal_type, d.user_name, CONCAT(d.first_name, ' ', d.last_name) AS user_fullname, d.first_name, d.last_name,
            e.branch_code, e.description AS branch_description, f.client_id AS merchant_code, f.merchant_name
            FROM webpos_transaction a
            LEFT JOIN terminals b ON b.id = a.terminal_id
            LEFT JOIN device_types c ON c.id = b.device_type_id
            LEFT JOIN merchant_terminal_users d ON d.id = a.terminal_user_id
            LEFT JOIN branch e ON e.id = a.branch_id
            LEFT JOIN clients f ON f.id = a.merchant_id
            WHERE {$where} AND {day a.transaction_date} ORDER BY a.transaction_date LIMIT 100000";
    }

    /** Customer ledger rows (`ezkard_transactions`) with the collapsed description. */
    private static function ledger(string $idAlias, string $conditions, string $limit = ''): string
    {
        $case = self::descriptionCase();

        return "SELECT CONCAT(c.first_name, ' ', c.last_name) AS CustomerName, c.mobile AS CustomerMobile, ezt.transaction_id AS {$idAlias}, ezt.amount AS Amount,
            CASE WHEN ty.direction = 1 THEN 'DEBIT' ELSE 'CREDIT' END AS TransactionType, ty.type AS TransactionType2,
            {$case} AS Description, ezt.timestamp AS TransactionDate
            FROM ezkard_transactions ezt
            INNER JOIN ezkard_accounts eza ON (eza.id = ezt.ezkard_id)
            INNER JOIN customers c ON (c.mobile = eza.mobile_number)
            INNER JOIN transaction_types ty ON (ty.id = ezt.trans_type_id)
            WHERE ezt.timestamp >= {from} AND ezt.timestamp < {to_next} AND ezt.`ezkard_id` > 0 {$conditions}
            AND c.mobile NOT LIKE '639%' AND c.mobile NOT LIKE '1639%' AND ezt.trans_status_id = 0 AND ty.direction IN (1, 0)
            ORDER BY ezt.timestamp {$limit}";
    }

    private static function ledgerColumns(string $idAlias, string $idLabel): array
    {
        return ['CustomerName' => 'Customer Name', 'CustomerMobile' => 'Customer Mobile', $idAlias => $idLabel, 'Amount' => 'Amount', 'TransactionType' => 'Transaction Type',
            'TransactionType2' => 'Transaction Type2', 'Description' => 'Description', 'TransactionDate' => 'Transaction Date'];
    }

    /** The Gaming Funds fee lines legacy kept in `customer_transaction_histories` until the cut-over. */
    private static function gamingFeeHistory(string $idAlias): string
    {
        return "SELECT CONCAT(c.`first_name`, ' ', c.`last_name`) AS CustomerName, c.mobile AS CustomerMobile, cth.transaction_id AS {$idAlias},
            (cth.`transaction_fee` + cth.vat) AS Amount, 'DEBIT' AS TransactionType, 'Fee - Debit' AS TransactionType2,
            'Gaming Funds Fee - Debit' AS Description, cth.created_date AS TransactionDate
            FROM customer_transaction_histories cth INNER JOIN customers c ON c.id = cth.customer_id
            WHERE created_date >= {from} AND created_date < {fix} AND description LIKE '%Gaming account deposit%'
            GROUP BY cth.id";
    }

    private static function suncashVouchers(string $where): string
    {
        return "SELECT TIMESTAMP(v.voucher_date) AS VOUCHER_DATE, v.voucher_code AS VOUCHER_NUMBER, v.`amount` AS AMOUNT, v.`mobile` AS MOBILE_NUMBER, v.email AS EMAIL_ADDRESS,
            CASE WHEN v.customer_owner_id > 0 THEN 'CustomerApp' WHEN w.id > 0 THEN 'WebPOS' WHEN v.merchant_owner_id > 0 THEN c2.dba_name
            ELSE IFNULL(c2.legal_name, IFNULL(bvg.field1, v.source)) END AS PURCHASE_SOURCE,
            CASE WHEN v.remarks LIKE '%CustomerApp%' THEN 'CustomerApp' WHEN v.remarks LIKE '%CustomerPortal%' THEN 'CustomerPortal'
            WHEN c.legal_name = '' THEN c.dba_name WHEN w2.id > 0 THEN 'WebPOS' WHEN v.status = 'ACTIVE' THEN NULL ELSE c.legal_name END AS REDEEMED_SOURCE,
            v.status AS STATUS, CASE WHEN v.status = 'ACTIVE' THEN NULL ELSE v.`update_date` END AS REDEEMED_VOIDED_DATE
            FROM merchant_vouchers v
            LEFT JOIN clients c ON (c.id = v.client_record_id)
            LEFT JOIN clients c2 ON (c2.id = v.merchant_owner_id)
            LEFT JOIN customers cc ON (cc.mobile = v.mobile)
            LEFT JOIN batch_voucher_generation bvg ON (bvg.voucher_number = v.`voucher_code`)
            LEFT JOIN webpos_transaction w ON (w.reference = v.`voucher_code` AND w.transaction_type = 'VOUCHER' AND w.merchant_id NOT IN (38) AND w.status = 0)
            LEFT JOIN webpos_transaction w2 ON (w2.reference = v.`voucher_code` AND w2.transaction_type = 'CASHOUT_VOUCHER' AND w2.merchant_id NOT IN (38) AND w2.status = 0)
            WHERE {$where}
            GROUP BY v.voucher_code ORDER BY v.voucher_date ASC LIMIT 1000000000";
    }

    private static function clientName(string $idColumn): string
    {
        return "(SELECT IFNULL(CASE WHEN legal_name = '' THEN dba_name ELSE legal_name END, '') AS client_name FROM clients WHERE id = {$idColumn})";
    }

    private static function unibucksVouchers(string $where): string
    {
        return "SELECT v.voucher_date AS VOUCHER_DATE, v.voucher_code AS VOUCHER_NUMBER, v.amount AS AMOUNT, receiver_mobile AS MOBILE_NUMBER, receiver_email AS EMAIL_ADDRESS,
            CASE WHEN v.purchased_channel = '3rdParty' THEN ".self::clientName('v.purchased_client_id').' ELSE v.purchased_channel END AS PURCHASE_SOURCE,
            CASE WHEN v.redeemed_channel = \'3rdParty\' THEN '.self::clientName('v.redeemed_client_id')." WHEN v.status = 'ACTIVE' THEN NULL ELSE v.redeemed_channel END AS REDEEMED_SOURCE,
            v.STATUS, CASE WHEN v.status = 'ACTIVE' THEN NULL ELSE v.`update_date` END AS REDEEMED_VOIDED_DATE
            FROM universal_vouchers v
            INNER JOIN universal_vouchers_logs uvl ON (uvl.universal_vouchers_id = v.id)
            LEFT JOIN `webpos_transaction` w ON (w.reference = v.`voucher_code` AND w.transaction_type = 'VOUCHER' AND w.merchant_id NOT IN (38) AND w.status = 0)
            LEFT JOIN `webpos_transaction` w2 ON (w2.reference = v.`voucher_code` AND w2.transaction_type = 'CASHOUT_VOUCHER' AND w2.merchant_id NOT IN (38) AND w2.status = 0)
            WHERE {$where}
            GROUP BY v.voucher_code ORDER BY v.voucher_date ASC LIMIT 1000000000";
    }

    private static function cardPayments(string $source, string $label): string
    {
        return "SELECT sc.date_created AS TransactionDate, '{$label}' AS TransactionType, IFNULL(c.dba_name, cc.customer_tag) AS BusinessName, sc.order_id AS OrderID,
            sc.reference_id AS ReferenceNumber, sc.name_on_card AS CardHolderName, sc.card_number AS CardNumber,
            CASE WHEN sc.iscard_fee_on_merch = 1 THEN ROUND((IFNULL(sc.amount, 0) + IFNULL(sc.billpay_fee, 0)), 2)
            ELSE ROUND((IFNULL(sc.amount, 0) - IFNULL(sc.discount_amount, 0) + IFNULL(sc.processing_fee, 0) + IFNULL(sc.transaction_fee, 0) + IFNULL(sc.billpay_fee, 0) + IFNULL(sc.billpay_vat, 0)), 2) END AS TotalAmountPaid,
            CASE WHEN sc.iscard_fee_on_merch = 1 THEN ROUND((IFNULL(sc.amount, 0) - IFNULL(sc.discount_amount, 0) - IFNULL(sc.processing_fee, 0) - IFNULL(sc.transaction_fee, 0) + IFNULL(sc.payment_suncash_vat, 0) + IFNULL(sc.payment_fee, 0)), 2)
            ELSE ROUND((IFNULL(sc.amount, 0) - IFNULL(sc.discount_amount, 0) - IFNULL(sc.payment_suncash_vat, 0) - IFNULL(sc.payment_fee, 0)), 2) END AS TotalCreditToMerchant
            FROM suncashme_cenpos_transaction_detail AS sc
            LEFT JOIN clients c ON c.merchant_key = sc.merchant_key
            LEFT JOIN customers cc ON cc.customer_tag = sc.merchant_key
            WHERE sc.`status` = 'active' AND sc.source = '{$source}' AND sc.date_created >= {from} AND sc.date_created <= {to}
            ORDER BY sc.date_created DESC";
    }

    /** The Sunpass ticketing reports read another database on the same server. */
    private const SUNPASS = '`1021893_sunpass`';

    /** @return array<string, array{label:string, dates:string, columns:array<string,string>, sql:string, sql_before_fix?:string, export_without?:list<string>, sheet?:string, note?:string}> */
    public static function all(): array
    {
        $sunpass = self::SUNPASS;

        return [
            'billpay' => [
                'label' => 'Billpay', 'dates' => 'range',
                'columns' => ['TransactionDate' => 'Transaction Date', 'CustomerName' => 'Customer Name', 'Customer_Mobile' => 'Customer Mobile', 'BillerCode' => 'Biller Code', 'BillAccountNumber' => 'Bill Account Number', 'BillAmount' => 'Bill Amount', 'Source' => 'Source'],
                'note' => "Legacy's export read seven fields the query never returns, so every Billpay row came out blank; the query's own seven columns are shown.",
                'sql' => 'SELECT bwt.transaction_date AS TransactionDate, bwt.customer_name AS CustomerName, bwt.customer_mobile AS Customer_Mobile, bwt.biller_code AS BillerCode,
                    bwt.bill_account_no AS BillAccountNumber, bwt.bill_amount AS BillAmount, '.self::BILLPAY_SOURCE." AS Source
                    FROM webpos_transaction w
                    INNER JOIN billpay_web_transactions bwt ON (bwt.transaction_id = w.transaction_id)
                    LEFT JOIN clients c ON (c.id = bwt.merchant_id)
                    WHERE transaction_type = 'BILLPAY' AND w.status = 0 AND bwt.transaction_date >= {from} AND bwt.transaction_date < {to_next}
                    AND bwt.transaction_id not like '00%' ORDER BY bwt.transaction_date ASC LIMIT 100000",
            ],
            'billpay_customer' => [
                'label' => 'Billpay Customer', 'dates' => 'range',
                'columns' => ['transaction_date' => 'Transaction Date', 'card_number' => 'Card Number', 'first_name' => 'First Name', 'last_name' => 'Last Name', 'biller_code' => 'Biller Code', 'bill_account_no' => 'Bill Account Number', 'bill_amount' => 'Bill Amount'],
                'sql' => 'SELECT bt.transaction_date, eza.card_number, c.first_name, c.last_name, bt.biller_code, bt.bill_account_no, bt.bill_amount
                    FROM billpay_transactions bt
                    INNER JOIN ezkard_accounts eza ON (eza.id = bt.ezkard_id)
                    INNER JOIN customers c ON (c.mobile = eza.mobile_number)
                    WHERE bt.transaction_date >= {from} AND bt.transaction_date <= {to} ORDER BY bt.transaction_date LIMIT 100000',
            ],
            'billpay_app' => [
                'label' => 'Billpay - App', 'dates' => 'range',
                'columns' => ['customer_name' => 'Customer Name', 'transaction_type' => 'Transaction Type', 'transaction_date' => 'Transaction Date', 'total_amount' => 'Total Amount'],
                'sql' => "SELECT CONCAT(cust.last_name, ', ', cust.`first_name`) AS customer_name, tt.`type` AS transaction_type, date_FORMAT(ez.`timestamp`, '%Y-%m-%d') AS transaction_date, SUM(ez.`amount`) AS total_amount
                    FROM ezkard_transactions ez
                    INNER JOIN transaction_types tt ON tt.`id` = ez.`trans_type_id`
                    LEFT JOIN ezkard_accounts eza ON eza.id = ez.`ezkard_id`
                    LEFT JOIN customers cust ON cust.`mobile` = eza.`mobile_number`
                    WHERE ez.timestamp >= {from} AND ez.timestamp <= {to}
                    GROUP BY customer_name, transaction_type, transaction_date ORDER BY transaction_date DESC LIMIT 100000",
            ],
            'billpay_web_transaction' => [
                'label' => 'Billpay - Web Transaction', 'dates' => 'range',
                'columns' => ['customer_name' => 'Customer Name', 'transaction_type' => 'Transaction Type', 'transaction_date' => 'Transaction Date', 'total_amount' => 'Total Amount'],
                'sql' => "SELECT bt.transaction_date, bt.customer_name, bt.mobile_number, bt.source AS transaction_type,
                    ROUND(ROUND(bt.amount, 2) + ROUND(bt.payment_suncash_vat, 2) + ROUND(bt.payment_fee, 2), 2) AS total_amount
                    FROM business_bill_transaction bt
                    LEFT JOIN clients AS cc ON cc.id = bt.merchant_client_id
                    WHERE bt.source IN ('checkout-wallet', 'suncashme-wallet') AND bt.status = 'P' AND bt.transaction_date >= {from} AND bt.transaction_date <= {to}
                    GROUP BY customer_name, transaction_type, transaction_date ORDER BY transaction_date DESC LIMIT 100000",
            ],
            'billpay_webpos_transaction' => [
                'label' => 'Billpay - Webpos Transaction', 'dates' => 'range',
                'columns' => ['customer_name' => 'Customer Name', 'transaction_type' => 'Transaction Type', 'trans_date' => 'Transaction Date', 'total_amount' => 'Total Amount'],
                'sql' => "SELECT CONCAT(cust.last_name, ', ', cust.`first_name`) AS customer_name, w.transaction_type, DATE_FORMAT(w.transaction_date, '%Y-%m-%d') AS trans_date, SUM(w.amount) AS total_amount
                    FROM webpos_transaction w
                    INNER JOIN clients c ON c.id = w.merchant_id
                    INNER JOIN merchant_terminal_users u ON u.id = w.terminal_user_id
                    LEFT JOIN ezkard_transactions ez ON (w.transaction_id = ez.transaction_id)
                    LEFT JOIN ezkard_accounts eza ON eza.id = ez.`ezkard_id`
                    LEFT JOIN customers cust ON cust.`mobile` = eza.`mobile_number`
                    WHERE w.transaction_date >= {from} AND w.transaction_date <= {to}
                    GROUP BY customer_name, transaction_type, trans_date ORDER BY trans_date DESC LIMIT 100000",
            ],
            'billpay_kiosk' => [
                'label' => 'Billpay - Kiosk', 'dates' => 'range',
                'columns' => ['customer_name' => 'Customer Name', 'transaction_type' => 'Transaction Type', 'trans_date' => 'Transaction Date', 'total_amount' => 'Total Amount'],
                'sql' => "SELECT CONCAT(cust.last_name, ', ', cust.`first_name`) AS customer_name, w.transaction_type, DATE_FORMAT(w.transaction_date, '%Y-%m-%d') AS trans_date, SUM(w.amount) AS total_amount
                    FROM webpos_transaction_kiosk w
                    LEFT JOIN ezkard_transactions ez ON (w.transaction_id = ez.transaction_id)
                    LEFT JOIN ezkard_accounts eza ON eza.id = ez.`ezkard_id`
                    LEFT JOIN customers cust ON cust.`mobile` = eza.`mobile_number`
                    WHERE w.transaction_date >= {from} AND w.transaction_date <= {to}
                    GROUP BY customer_name, transaction_type, trans_date ORDER BY trans_date DESC LIMIT 100000",
            ],
            'card_transaction_for_sunpass' => [
                'label' => 'Card Transaction for sunpass', 'dates' => 'range',
                'columns' => ['date_created' => 'Date Created', 'event_name' => 'Event Name', 'dba_name' => 'Dba Name', 'order_id' => 'Order Id', 'reference_id' => 'Reference Id', 'name_on_card' => 'Name on card', 'card_type' => 'Card Type', 'amount' => 'Amount', 'transaction_fee' => 'Transaction Fee', 'sunpass_fee' => 'Sunpass Fee', 'payment_fee' => 'Payment Fee', 'payment_suncash_vat' => 'Payment Suncash Vat'],
                'sql' => "SELECT sc.`date_created`, e.name AS event_name, c.`dba_name`, sc.order_id, sc.`reference_id`, sc.`name_on_card`, sc.`card_type`, sc.`amount`, sc.`transaction_fee`, sc.`sunpass_fee`, sc.`payment_fee`, sc.`payment_suncash_vat`
                    FROM suncashme_cenpos_transaction_detail AS sc
                    INNER JOIN clients c ON c.merchant_key = sc.merchant_key
                    INNER JOIN {$sunpass}.orders_tickets ot ON ot.order_id = sc.order_id
                    INNER JOIN {$sunpass}.`events` e ON e.id = ot.event_id
                    WHERE sc.merchant_key = 'eb510843beadfe4dbad2dd6d37979460634c8c9c9002a33595b4964670d1497b' AND sc.date_created >= {from} AND sc.date_created <= {to}
                    ORDER BY sc.date_created DESC LIMIT 100000",
            ],
            'cashout_code' => [
                'label' => 'Cashout Code', 'dates' => 'range',
                'columns' => ['MerchantName' => 'Merchant Name', 'Location' => 'Location', 'TotalTransactionCount' => 'Total Transaction Count', 'SingleLargestAmount' => 'Single Largest Amount', 'TotalAmount' => 'Total Amount', 'TransactionDate' => 'Transaction Date'],
                'export_without' => ['TransactionDate'],
                'sql' => "SELECT c.legal_name AS MerchantName, b.description AS Location, COUNT(w.id) AS TotalTransactionCount, MAX(w.amount) AS SingleLargestAmount, SUM(w.amount) AS TotalAmount,
                    MONTH(w.transaction_date) AS Month, Year(w.transaction_date) AS Year, w.transaction_date AS TransactionDate
                    FROM webpos_transaction w
                    INNER JOIN clients c ON c.id = w.merchant_id
                    INNER JOIN merchant_terminal_users u ON u.id = w.terminal_user_id
                    INNER JOIN branch b ON w.branch_id = b.id
                    WHERE w.status = 0 AND w.transaction_type IN ('CASHOUT_CODE') AND {day w.transaction_date}
                    GROUP BY merchant_name, b.description, Month, Year ORDER BY Month, Year LIMIT 100000",
            ],
            'credit_card' => [
                'label' => 'Credit Card', 'dates' => 'range',
                'columns' => ['date_time' => 'Transaction Date', 'PaymentMethod' => 'Payment Method', 'CustomerName' => 'Customer Name', 'last_four_digit_card' => 'Last four digit card', 'TransactionID' => 'Transaction ID', 'STATUS' => 'Status', 'amount' => 'Amount'],
                'sql' => "SELECT sct.date_created AS date_time, IFNULL(c.dba_name, cc.customer_tag) AS merchant, 'Credit Card' AS PaymentMethod, sct.name_on_card AS CustomerName,
                    RIGHT(sct.card_number, 4) AS last_four_digit_card, sct.reference_id AS TransactionID, 'success' AS STATUS, sct.amount
                    FROM suncashme_cenpos_transaction_detail sct
                    LEFT JOIN clients c ON c.merchant_key = sct.merchant_key
                    LEFT JOIN customers cc ON cc.customer_tag = sct.merchant_key
                    WHERE sct.date_created >= {from} AND sct.date_created <= {to} AND sct.STATUS = 'active' ORDER BY sct.date_created LIMIT 1000000",
            ],
            'customer_transaction' => [
                'label' => 'Customer Transaction Report', 'dates' => 'range',
                'columns' => self::ledgerColumns('TransactionID', 'Transaction ID'),
                'note' => "For a Start Date on or before the gaming-deposit cut-over (2022-08-09) legacy ran a second query (active customers plus the Gaming Funds fee lines) but then listed the first query's rows by mistake; the second query's rows are listed.",
                'sql' => self::ledger('TransactionID', "AND description NOT LIKE 'Bank deposit -Log%' AND ezt.description NOT LIKE 'Load via Card - FEE%' AND ezt.description NOT LIKE 'Cashout Via AccessToken -Fee%'", 'asc'),
                'sql_before_fix' => '('.self::ledger('TransactionID', "AND description NOT LIKE 'Bank deposit -Log%' AND ezt.description NOT LIKE 'Load via Card - FEE%' AND ezt.description NOT LIKE 'Cashout Via AccessToken -Fee%' AND c.status = 'A'").') UNION ALL ('.self::gamingFeeHistory('TransactionID').') ORDER BY TransactionDate ASC',
            ],
            'global_sales' => [
                'label' => 'Global Sales', 'dates' => 'range',
                'columns' => ['merchant_name' => 'Merchant Name', 'transaction_count' => 'Transaction Count', 'transaction_amount' => 'Transaction Amount', 'transaction_type' => 'Transaction Type', 'Customer_Fee' => 'Customer Fee', 'GovtFeeVat' => 'Govt Fee Vat', 'GovtFeeStampTax' => 'Govt Fee Stamp Tax', 'StartDate' => 'Start Date', 'EndDate' => 'End Date'],
                'note' => "Start / End Date are legacy's text MIN / MAX (month first), so a range across months or years can show a wrong first / last day.",
                'sql' => "SELECT c.legal_name AS merchant_name, CONCAT(u.first_name, ' ', u.last_name) AS CashierName, COUNT(w.id) AS transaction_count, SUM(w.amount) AS transaction_amount,
                    transaction_type, SUM(fee_amount) AS Customer_Fee, SUM(vat_amount) GovtFeeVat, SUM(stamp_amount) GovtFeeStampTax,
                    MIN(DATE_FORMAT(transaction_date, '%m/%d/%Y')) AS StartDate, MAX(DATE_FORMAT(transaction_date, '%m/%d/%Y')) AS EndDate
                    FROM webpos_transaction w
                    INNER JOIN clients c ON c.id = w.merchant_id
                    INNER JOIN merchant_terminal_users u ON u.id = w.terminal_user_id
                    WHERE w.status = 0 AND w.transaction_date >= {from} AND w.transaction_date <= {to}
                    GROUP BY merchant_name, CashierName, transaction_type ORDER BY w.transaction_date LIMIT 100000",
            ],
            'load' => ['label' => 'Load', 'dates' => 'range', 'columns' => self::WEBPOS_COLUMNS, 'sql' => self::webposWide("a.transaction_type = 'LOAD'")],
            'merchant_transactions' => [
                'label' => 'Merchant Transactions', 'dates' => 'merchant',
                'columns' => ['Merchant' => 'Merchant', 'TransactionType' => 'Transaction Type', 'description' => 'Description', 'Amount' => 'Amount', 'TransactionDate' => 'Transaction Date'],
                'note' => "Legacy's Merchant list called a function its model doesn't have, so the dropdown stayed empty and this report couldn't be run; the list is the clients (Merchant Name, else DBA, Legal or User name).",
                'sql' => "SELECT c.id, c.client_id, CASE WHEN c.merchant_name = '' THEN c.dba_name WHEN c.dba_name = '' THEN c.legal_name ELSE c.merchant_name END AS Merchant,
                    ty.type AS TransactionType, description, FORMAT(SUM(ct.amount), 2) AS Amount, timestamp AS TransactionDate
                    FROM client_transactions ct
                    INNER JOIN clients c ON (ct.client_record_id = c.id)
                    INNER JOIN transaction_types ty ON (ty.id = ct.trans_type_id)
                    WHERE c.id = {merchant} AND ct.timestamp >= {from} AND ct.timestamp < {to}
                    GROUP BY c.client_id, TransactionType, DAY(TransactionDate) ORDER BY TransactionDate ASC LIMIT 100000",
            ],
            'merchant_vouchers' => [
                'label' => 'Merchant Vouchers', 'dates' => 'range',
                'columns' => ['voucher_code' => 'Voucher Code', 'batch_number' => 'Batch Number', 'serial_number' => 'Serial Number', 'voucher_date' => 'Voucher Date', 'amount' => 'Amount', 'status' => 'Status', 'mobile' => 'Mobile', 'email' => 'Email', 'create_date' => 'Transaction Date', 'update_date' => 'Update Date', 'remarks' => 'Remarks'],
                'sql' => 'SELECT voucher_code, batch_number, serial_number, voucher_date, amount, status, mobile, email, create_date, update_date, remarks
                    FROM merchant_vouchers WHERE {day create_date} ORDER BY create_date LIMIT 100000',
            ],
            'merchant_redeemed_vouchers' => [
                'label' => 'Merchant Redeemed Vouchers', 'dates' => 'range',
                'columns' => ['voucher_code' => 'Voucher Code', 'amount' => 'Amount', 'redeemed_date' => 'Transaction Date', 'merchant' => 'Merchant'],
                'sql' => "SELECT v.`voucher_code`, v.`amount`, v.`update_date` AS redeemed_date,
                    CASE WHEN c.legal_name = 'Business Billpay Clearing Acct.' THEN 'SunCashAccount' ELSE c.legal_name END AS merchant
                    FROM merchant_vouchers v INNER JOIN clients c ON (c.id = v.client_record_id)
                    WHERE v.`update_date` >= {from} AND v.`update_date` <= {to} AND v.`status` = 'REDEEMED' AND v.`customer_owner_id` != 678
                    ORDER BY v.`update_date` LIMIT 1000000",
            ],
            'mobile_app_debit_credit' => [
                'label' => 'Mobile App Debit/Credit Card', 'dates' => 'range',
                'columns' => ['CustomerName' => 'Customer Name', 'customer_mobile' => 'Customer Mobile', 'customer_email' => 'Customer Email', 'TransactionType' => 'Transaction Type', 'PaymentMethod' => 'Payment Method', 'card_type' => 'Card Type', 'last_four_digit_card' => 'CardLastFourDigit', 'TransactionID' => 'TransactionID', 'amount' => 'Amount', 'payment_fee' => 'Fee', 'TotalAmount' => 'Total Amount', 'date_time' => 'Transaction Date'],
                'sql' => "SELECT w.transaction_date AS date_time, w.reference_id, CONCAT(c.first_name, ' ', c.last_name) AS CustomerName, c.mobile AS customer_mobile, c.email AS customer_email,
                    cc.card_last_four_digits AS last_four_digit_card, cc.card_type, w.transaction_type AS TransactionType, w.payment_method AS PaymentMethod, w.transaction_id AS TransactionID,
                    w.amount, w.fee_amount AS payment_fee, w.total_amount AS TotalAmount
                    FROM webpos_transaction_chp w
                    LEFT JOIN cenpos_logs4 cl ON (cl.reference_number = w.reference_id)
                    LEFT JOIN (SELECT token_id, card_last_four_digits, card_type, MAX(id) AS maxid FROM customer_creditcard GROUP BY token_id) AS cc ON (cc.token_id = cl.recurring_token_id)
                    LEFT JOIN customers c ON (c.ezkard_account_id = w.customer_id)
                    WHERE w.transaction_date >= {from} AND w.transaction_date <= {to} AND payment_method = 'CreditCard' AND transaction_type = 'LOAD'
                    ORDER BY w.transaction_date LIMIT 100000",
            ],
            'money_transfer' => [
                'label' => 'Money Transfer', 'dates' => 'range',
                'columns' => ['MerchantName' => 'Merchant Name', 'Location' => 'Location', 'TotalTransactionCount' => 'Total Transaction Count', 'SingleLargestAmount' => 'Single Largest Amount', 'TotalAmount' => 'Total Amount', 'TransactionDate' => 'Transaction Date'],
                'export_without' => ['TransactionDate'],
                'sql' => "SELECT c.legal_name AS MerchantName, b.description AS Location, COUNT(w.id) AS TotalTransactionCount, MAX(w.amount) AS SingleLargestAmount, SUM(w.amount) AS TotalAmount,
                    MONTH(w.transaction_date) AS Month, Year(w.transaction_date) AS Year, w.transaction_date AS TransactionDate
                    FROM webpos_transaction w
                    INNER JOIN clients c ON c.id = w.merchant_id
                    INNER JOIN merchant_terminal_users u ON u.id = w.terminal_user_id
                    INNER JOIN branch b ON w.branch_id = b.id
                    WHERE w.status = 0 AND w.transaction_type IN ('MONEY_TRANSFER') AND {day w.transaction_date}
                    GROUP BY merchant_name, b.description, Month, Year ORDER BY Month, Year LIMIT 100000",
            ],
            'nib_voucher' => [
                'label' => 'NIB Vouchers', 'dates' => 'range',
                'columns' => ['name' => 'Customer Name', 'mobile' => 'Customer Mobile', 'email' => 'Customer Email', 'NibNumber' => 'NIB Number', 'voucher_code' => 'Voucher Code', 'pin' => 'Pin', 'Amount' => 'Amount', 'status' => 'Status', 'create_date' => 'Transaction Date', 'update_date' => 'Redemption Date', 'Source' => 'Redemption Source'],
                'sql' => "SELECT b.name, a.mobile, a.email, FORMAT(a.amount, 2) AS Amount, b.field2 AS NibNumber, a.voucher_code, b.pin, a.status, a.create_date, a.update_date,
                    CASE WHEN a.remarks != '' THEN 'CUSTOMER' WHEN a.client_record_id = {voucher_client} THEN 'WEBPOS' WHEN a.client_record_id NOT IN ({voucher_client}, '-1') THEN '3rd PARTY' ELSE '' END AS Source
                    FROM batch_voucher_generation b INNER JOIN merchant_vouchers a ON (b.voucher_number = a.voucher_code)
                    WHERE {day a.create_date} ORDER BY a.create_date LIMIT 100000",
            ],
            'other_merchant_transaction' => ['label' => 'Other Merchant Transaction', 'dates' => 'range', 'columns' => self::WEBPOS_COLUMNS, 'sql' => self::webposWide("a.`merchant_id` != '288'")],
            'phone_to_phone' => [
                'label' => 'Phone to Phone Money Transfer', 'dates' => 'range',
                'columns' => ['TransactionType' => 'Transaction Type', 'transaction_date' => 'Transaction Date', 'first_name' => 'First Name', 'last_name' => 'Last Name', 'transaction_id' => 'Transaction ID', 'amount' => 'Amount', 'description' => 'Description'],
                'sql' => "SELECT 'Phone to Phone' AS TransactionType, ezt.timestamp AS transaction_date, c.first_name, c.last_name, ezt.transaction_id, ezt.amount, ezt.description
                    FROM ezkard_transactions ezt
                    INNER JOIN ezkard_accounts eza ON (eza.id = ezt.ezkard_id)
                    INNER JOIN customers c ON (c.mobile = eza.mobile_number)
                    INNER JOIN transaction_types ty ON (ty.id = ezt.trans_type_id)
                    WHERE ezt.description LIKE '%P2P%' AND TIMESTAMP >= {from} AND TIMESTAMP <= {to} AND ezt.trans_type_id = 16 ORDER BY TIMESTAMP LIMIT 100000",
            ],
            'purchase_or_sales' => ['label' => 'Purchase or sales', 'dates' => 'range', 'columns' => self::WEBPOS_COLUMNS, 'sql' => self::webposWide("a.transaction_type = 'PURCHASE'")],
            'suncash_total_transaction' => [
                'label' => 'Suncash Total Transactions', 'dates' => 'range',
                'columns' => ['merchant_name' => 'Merchant Name', 'location' => 'Location', 'total_transaction_count' => 'Total Transaction Count', 'single_largest_amount' => 'Single Largest Amount', 'total_amount' => 'Total Amount'],
                'sql' => "SELECT c.legal_name AS merchant_name, b.description AS location, COUNT(w.id) AS total_transaction_count, MAX(w.`amount`) single_largest_amount, SUM(w.amount) AS total_amount
                    FROM webpos_transaction w
                    INNER JOIN clients c ON c.id = w.merchant_id
                    INNER JOIN merchant_terminal_users u ON u.id = w.terminal_user_id
                    INNER JOIN branch b ON w.branch_id = b.id
                    WHERE w.status = 0 AND w.transaction_type IN ('MONEY_TRANSFER') AND w.transaction_date >= {from} AND w.transaction_date <= {to}
                    GROUP BY merchant_name, location ORDER BY w.transaction_date LIMIT 100000",
            ],
            'suncash_me_business_balance' => [
                'label' => 'SUNCASHME Business Balance', 'dates' => 'none',
                'columns' => ['dba_name' => 'Dba Name', 'Balance' => 'Client Prefund'],
                'sql' => "SELECT dba_name, FORMAT(client_prefund, 2) AS Balance FROM clients WHERE merchant_type_id = '1' LIMIT 100000",
            ],
            'suncash_me_customer_balance' => [
                'label' => 'SUNCASHME Customer Balance', 'dates' => 'none',
                'columns' => ['first_name' => 'First Name', 'last_name' => 'Last Name', 'email' => 'Email', 'mobile' => 'Mobile', 'customer_tag' => 'Customer Tag', 'Balance' => 'Card Balance'],
                'sql' => "SELECT c.`first_name`, c.`last_name`, c.`email`, c.`mobile`, c.`customer_tag`, FORMAT(ea.card_balance, 2) AS Balance
                    FROM customers c JOIN ezkard_accounts ea ON ea.id = c.ezkard_account_id
                    WHERE c.`customer_tag` != '' AND c.status = 'A' AND c.mobile LIKE '124%' ORDER BY c.id",
            ],
            'suncash_me_transaction' => [
                'label' => 'SUNCASH ME Transaction', 'dates' => 'range',
                'columns' => ['date_time' => 'Transaction Date', 'merchant' => 'Merchant', 'PaymentMethod' => 'Payment Method', 'CustomerName' => 'Customer Name', 'last_four_digit_card' => 'Last Four Digit Card', 'TransactionID' => 'Transaction Id', 'STATUS' => 'Status', 'amount' => 'Amount'],
                'sql' => "SELECT sct.date_created AS date_time, IFNULL(c.dba_name, cc.customer_tag) AS merchant, 'Credit Card' AS PaymentMethod, sct.name_on_card AS CustomerName,
                    RIGHT(sct.card_number, 4) AS last_four_digit_card, sct.reference_id AS TransactionID, 'success' AS STATUS, sct.amount
                    FROM suncashme_cenpos_transaction_detail sct
                    LEFT JOIN clients c ON c.merchant_key = sct.merchant_key
                    LEFT JOIN customers cc ON cc.customer_tag = sct.merchant_key
                    WHERE sct.date_created >= {from} AND sct.date_created <= {to} AND sct.STATUS = 'active' ORDER BY sct.date_created LIMIT 100000",
            ],
            'supervalue_transaction' => [
                'label' => 'Supervalue Transactions', 'dates' => 'range', 'columns' => self::WEBPOS_COLUMNS,
                'note' => "Legacy's export read the date from a field the query doesn't return, so Transaction Date came out blank (and the export's month sheets failed); the real date is shown.",
                'sql' => self::webposWide("a.`merchant_id` = '288'"),
            ],
            'ticket' => [
                'label' => 'Ticket', 'dates' => 'range', 'columns' => self::WEBPOS_COLUMNS,
                'note' => 'Same blank Transaction Date fix as Supervalue Transactions.',
                'sql' => self::webposWide("(a.transaction_type = 'TICKETS' OR a.transaction_type = 'TICKETS_MOVIE')"),
            ],
            'ticket_purchase' => [
                'label' => 'Ticket Purchase', 'dates' => 'range',
                'columns' => ['TransactionType' => 'Transaction Type', 'transaction_date' => 'Transaction Date', 'first_name' => 'First Name', 'last_name' => 'Last Name', 'transaction_id' => 'Transaction ID', 'amount' => 'Amount', 'description' => 'Description'],
                'sql' => "SELECT 'Ticket Purchase' AS TransactionType, ezt.timestamp AS transaction_date, c.first_name, c.last_name, ezt.transaction_id, ezt.amount, ezt.description
                    FROM ezkard_transactions ezt
                    INNER JOIN ezkard_accounts eza ON (eza.id = ezt.ezkard_id)
                    INNER JOIN customers c ON (c.mobile = eza.mobile_number)
                    INNER JOIN transaction_types ty ON (ty.id = ezt.trans_type_id)
                    WHERE ezt.description LIKE '%Event Ticket transaction%' AND TIMESTAMP >= {from} AND TIMESTAMP <= {to} AND ezt.trans_type_id = 51 ORDER BY TIMESTAMP LIMIT 100000",
            ],
            'ticket_purchase_not_redeemed' => [
                'label' => 'Tickets Purchased Not Redeemed Report', 'dates' => 'range',
                'columns' => ['event_name' => 'Event Name', 'order_id' => 'Order id', 'first_name' => 'First Name', 'last_name' => 'Last Name', 'ticket' => 'Ticket', 'ticket_code' => 'Ticket Code', 'price' => 'Price', 'source' => 'Source'],
                'sql' => "SELECT e.name AS event_name, ot.order_id, ot.first_name, ot.last_name, t.title AS ticket, tt.ticket_code, t.`price`, ot.`source`
                    FROM {$sunpass}.ticket_table tt
                    INNER JOIN {$sunpass}.orders_tickets ot ON ot.id = tt.order_id
                    INNER JOIN {$sunpass}.EVENTS e ON e.id = tt.event_id
                    INNER JOIN {$sunpass}.tickets t ON t.id = tt.ticket_id
                    WHERE 1 = 1 AND tt.ticket_status = 'sold' AND {day ot.created_at} ORDER BY ot.created_at DESC LIMIT 100000",
            ],
            'customer_balance' => [
                'label' => '*Customer wallet/App balances', 'dates' => 'none',
                'columns' => ['CustomerName' => 'Customer Name', 'MobileNumber' => 'Mobile Number', 'Balance' => 'Balance', 'IslandName' => 'Island Name'],
                'sql' => "SELECT CONCAT(c.first_name, ' ', c.last_name) AS CustomerName, c.mobile AS MobileNumber, FORMAT(ez.card_balance, 2) AS Balance, i.name AS IslandName
                    FROM customers c
                    INNER JOIN ezkard_accounts ez ON (ez.mobile_number = c.mobile)
                    LEFT JOIN island i ON (i.id = c.`island`)
                    WHERE c.status = 'A' AND c.mobile LIKE '124%' ORDER BY i.name DESC LIMIT 100000000",
            ],
            'customer_merchant_balance' => [
                'label' => '*Customer Merchant Balances', 'dates' => 'none',
                'columns' => ['BusinessName' => 'Business Name', 'Balance' => 'Balance'],
                'note' => 'Legacy grouped the businesses by their balance (GROUP BY client_prefund), so only one arbitrary business per distinct balance was listed; every business is listed, lowest balance first.',
                'sql' => "SELECT dba_name AS BusinessName, merchant_name, FORMAT(client_prefund, 2) AS Balance FROM clients
                    WHERE merchant_type_id = '1' AND dba_name NOT LIKE '%Clearing%' AND dba_name NOT LIKE '%test%' AND dba_name NOT IN ('PMSP', 'lemtest')
                    ORDER BY client_prefund ASC LIMIT 100000",
            ],
            'unclaimed_suncash' => [
                'label' => '*Unclaimed Suncash Voucher Balances', 'dates' => 'end', 'columns' => self::VOUCHER_COLUMNS,
                'sql' => self::suncashVouchers("v.voucher_date < {to_next} AND v.mobile NOT LIKE '639%' AND v.mobile NOT LIKE '1639%' AND v.status = 'ACTIVE'"),
            ],
            'unclaimed_unibucks' => [
                'label' => '*Unclaimed Unibucks Voucher Balances', 'dates' => 'end', 'columns' => self::VOUCHER_COLUMNS,
                'sql' => self::unibucksVouchers("v.voucher_date < {to_next} AND v.receiver_mobile NOT LIKE '639%' AND v.receiver_mobile NOT LIKE '1639%' AND v.status = 'ACTIVE'"),
            ],
            'unclaimed_money_transfer' => [
                'label' => '*Unclaimed money Transfer Balances', 'dates' => 'end',
                'columns' => ['TransactionDateRequested' => 'Transaction Date', 'TransactionTimeRequested' => 'Transaction Time', 'MerchantName' => 'Merchant Name', 'SenderName' => 'Sender Name', 'SenderMobile' => 'Sender Mobile', 'ReceiverName' => 'Receiver Name', 'ReceiverMobile' => 'Receiver Mobile', 'CashoutReference' => 'Cashout Reference', 'Amount' => 'Amount', 'Status' => 'Status', 'expiration_date' => 'Expiration Date', 'transaction_status' => 'Transaction Status'],
                'sql' => "SELECT c.legal_name AS MerchantName, CONCAT(ctd.sender_fname, ' ', ctd.sender_lname) AS SenderName, ctd.sender_mobile AS SenderMobile,
                    CONCAT(ctd.bene_fname, ' ', ctd.bene_lname) AS ReceiverName, ctd.bene_mobile AS ReceiverMobile,
                    date(ct.date_requested) AS TransactionDateRequested, time(ct.date_requested) AS TransactionTimeRequested,
                    ct.cashout_reference AS CashoutReference, round(ct.amount, 2) AS Amount,
                    CASE WHEN ct.status = 0 THEN 'Unclaimed Money Transfer' ELSE ct.status END AS Status,
                    DATE_ADD(ct.date_requested, INTERVAL 90 DAY) AS expiration_date,
                    CASE WHEN (DATEDIFF(NOW(), DATE(ct.date_requested)) - 90) > 0 THEN 'Expired' ELSE 'Active' END AS transaction_status
                    FROM cashout_transactionsv3 ct
                    INNER JOIN clients c ON c.id = ct.initiating_merchant
                    LEFT JOIN cashout_transaction_detailsv3 ctd ON (ct.id = ctd.cashout_id)
                    WHERE ct.status = 0 AND {day_le ct.date_requested} ORDER BY ct.date_requested LIMIT 100000",
            ],
            'load_credit_card' => [
                'label' => '*Load Credit card report', 'dates' => 'range',
                'columns' => ['TransactionDate' => 'Transaction Date', 'CustomerName' => 'Customer Name', 'CustomerMobile' => 'Customer Mobile', 'CustomerEmail' => 'Customer Email', 'ReferenceNumber' => 'Reference Number', 'Amount' => 'Amount', 'Fee' => 'Fee', 'TotalAmount' => 'Total Amount'],
                'sql' => "SELECT cclt.`transaction_date` AS TransactionDate, CONCAT(c.`first_name`, ' ', c.`last_name`) AS CustomerName, c.`mobile` AS CustomerMobile, c.`email` AS CustomerEmail,
                    cclt.`reference_number` AS ReferenceNumber, cclt.`amount` AS Amount, cclt.`fee_amount` AS Fee, (cclt.`amount` + cclt.`fee_amount`) AS TotalAmount
                    FROM credit_card_load_transactions cclt INNER JOIN customers c ON c.`id` = cclt.`customer_id`
                    WHERE cclt.`transaction_date` >= {from} AND cclt.`transaction_date` <= {to} AND cclt.`status` = 'processed'
                    ORDER BY cclt.id DESC LIMIT 100000000",
            ],
            'suncashme_credit_card' => ['label' => '*SuncashMe Credit card report', 'dates' => 'range', 'columns' => self::CARD_PAYMENT_COLUMNS, 'sql' => self::cardPayments('payment', 'SuncashMe Credit Card Payment')],
            'checkout_credit_card' => ['label' => '*Checkout Credit card report', 'dates' => 'range', 'columns' => self::CARD_PAYMENT_COLUMNS, 'sql' => self::cardPayments('checkout', 'Checkout Credit Card Payment')],
            'utility' => [
                'label' => '*Utility Billpay transactions', 'dates' => 'range',
                'columns' => ['TransactionDate' => 'Transaction Date', 'CustomerName' => 'Customer Name', 'CustomerMobile' => 'Customer Mobile', 'BillerCode' => 'Biller Code', 'BillAccountNumber' => 'Bill Account Number', 'BillAmount' => 'Bill Amount', 'Source' => 'Source'],
                'sql' => "(SELECT bwt.transaction_date AS TransactionDate, bwt.customer_name AS CustomerName, bwt.customer_mobile AS CustomerMobile, bwt.biller_code AS BillerCode,
                    bwt.bill_account_no AS BillAccountNumber, bwt.bill_amount AS BillAmount,
                    CASE WHEN client_id = 'quickpay' THEN 'KIOSK' WHEN client_id = '2014-01' THEN 'WebPOS' WHEN client_id LIKE 'SC%' THEN 'WebPOS' ELSE client_id END AS Source
                    FROM billpay_web_transactions bwt LEFT JOIN clients c ON (c.id = bwt.merchant_id)
                    WHERE bwt.transaction_id not like '00%' AND bwt.transaction_date >= {from} AND {day_lt_next bwt.transaction_date} AND bwt.status = 0)
                    UNION ALL
                    (SELECT bt.transaction_date AS TransactionDate,
                    CASE WHEN ezkard_id = -1 THEN kua.bill_account_name ELSE CONCAT(c.first_name, ' ', c.last_name) END AS CustomerName,
                    CASE WHEN ezkard_id = -1 THEN kua.customer_mobile ELSE eza.card_number END AS CustomerMobile,
                    bt.biller_code AS BillerCode, bt.bill_account_no AS BillAccountNumber, bt.bill_amount AS BillAmount,
                    CASE WHEN ezkard_id = -1 THEN 'KIOSK' ELSE 'CustomerApp' END AS Source
                    FROM billpay_transactions bt
                    LEFT JOIN ezkard_accounts eza ON (eza.id = bt.ezkard_id)
                    LEFT JOIN customers c ON (c.mobile = eza.mobile_number)
                    LEFT JOIN kiosk_utility_accounts kua ON kua.bill_account_no = bt.bill_account_no
                    WHERE bt.transaction_date >= {from} AND {day_lt_next bt.transaction_date} GROUP BY bt.id)
                    ORDER BY TransactionDate ASC",
            ],
            'business_billpay' => [
                'label' => '*Business Billpay transactions', 'dates' => 'range',
                'columns' => ['TransactionDate' => 'Transaction Date', 'CustomerName' => 'Customer Name', 'MerchantName' => 'Merchant Name', 'Amount' => 'Amount', 'Source' => 'Source'],
                'sql' => '(SELECT bwt.transaction_date AS TransactionDate, bwt.customer_name AS CustomerName, bwt.biller_code AS MerchantName, bwt.bill_amount AS Amount, '.self::BILLPAY_SOURCE." AS Source
                    FROM webpos_transaction w
                    INNER JOIN billpay_web_transactions bwt ON (bwt.transaction_id = w.transaction_id)
                    LEFT JOIN clients c ON (c.id = bwt.merchant_id)
                    WHERE transaction_type = 'BILLPAY' AND w.status = 0 AND bwt.transaction_date >= {from} AND bwt.transaction_date <= {to} AND bwt.transaction_id like '00%' LIMIT 100000)
                    UNION ALL
                    (SELECT ezt.timestamp AS TransactionDate, bbt.`customer_name` AS CustomerName, c.`dba_name` AS MerchantName, bbt.`amount` AS Amount, 'CustomerApp' AS Source
                    FROM business_bill_transaction bbt
                    LEFT JOIN clients c ON (c.id = bbt.merchant_client_id)
                    INNER JOIN (SELECT MIN(transaction_id) AS transaction_id, reference_id, `timestamp` FROM ezkard_transactions
                        GROUP BY reference_id) AS ezt ON (bbt.transaction_id = ezt.reference_id)
                    WHERE bbt.is_customer_payee = 0 AND c.merchant_type_id NOT IN (2, 3) AND {day bbt.transaction_date} AND bbt.`status` = 'P' AND bbt.source_app IN ('CUSTOMER') LIMIT 100000)
                    ORDER BY TransactionDate ASC",
            ],
            'topup' => [
                'label' => '*Topup transactions', 'dates' => 'range',
                'columns' => ['TransactionDate' => 'Transaction Date', 'CustomerName' => 'Customer Name', 'CustomerMobile' => 'Customer Mobile', 'CustomerEmail' => 'Customer Email', 'BeneMobile' => 'Bene Mobile', 'Amount' => 'Amount', 'ProductID' => 'Product ID', 'Provider' => 'Provider', 'Source' => 'Source'],
                'sql' => "SELECT mtt.`transaction_date` AS TransactionDate, CONCAT(c.first_name, ' ', c.last_name) CustomerName, mtt.mobile_number AS CustomerMobile, c.`email` AS CustomerEmail,
                    mtt.`mobile_number` AS BeneMobile, mtt.`amount` AS Amount, mtt.`product_id` AS ProductID, mtt.`provider` AS Provider,
                    CASE WHEN mtt.`source` = 1 AND w.source = 'retailwebpos' THEN 'RETAILWEBPOS' WHEN mtt.`source` = 1 AND w.source = 'webpos' THEN 'WEBPOS'
                    WHEN mtt.`source` = 1 AND (w.source = '' OR w.source IS NULL) THEN 'WEBPOS' WHEN mtt.`source` = 2 THEN 'CUSTOMER_APP' WHEN mtt.`source` = 3 THEN 'FASTPAY'
                    WHEN mtt.`source` = 4 THEN '3RD PARTY' ELSE mtt.`source` END AS Source
                    FROM mobile_topup_transactions mtt
                    LEFT JOIN customers c ON (c.`ezkard_account_id` = mtt.`ezkard_id` AND mtt.source not in (3, 4))
                    LEFT JOIN `webpos_transaction` w ON w.transaction_id = mtt.provider_ref_number AND mtt.source = 1
                    WHERE mtt.`transaction_date` >= {from} AND mtt.`transaction_date` < {to_next}
                    ORDER BY mtt.`transaction_date` ASC LIMIT 1000000",
            ],
            'suncash_based_purchase' => [
                'label' => '*Suncash Vouchers report based on purchase date', 'dates' => 'range', 'columns' => self::VOUCHER_COLUMNS,
                'sql' => self::suncashVouchers("v.voucher_date >= {from} AND v.voucher_date < {to_next} AND v.mobile NOT LIKE '639%' AND v.mobile NOT LIKE '1639%'"),
            ],
            'unibucks_based_purchase' => [
                'label' => '*Unibucks Vouchers report based on purchase date', 'dates' => 'range', 'columns' => self::VOUCHER_COLUMNS,
                'sql' => self::unibucksVouchers("v.voucher_date >= {from} AND v.voucher_date < {to_next} AND v.receiver_mobile NOT LIKE '639%' AND v.receiver_mobile NOT LIKE '1639%'"),
            ],
            'suncash_based_redeem' => [
                'label' => '*Suncash Vouchers report based on redeem date', 'dates' => 'range', 'columns' => self::VOUCHER_COLUMNS,
                'sql' => self::suncashVouchers("v.update_date >= {from} AND v.update_date < {to_next} AND v.status != 'ACTIVE' AND v.mobile NOT LIKE '639%' AND v.mobile NOT LIKE '1639%'"),
            ],
            'unibucks_based_redeem' => [
                'label' => '*Unibucks Vouchers report based on redeem date', 'dates' => 'range', 'columns' => self::VOUCHER_COLUMNS,
                'sql' => self::unibucksVouchers("v.update_date >= {from} AND v.update_date < {to_next} AND v.status != 'ACTIVE' AND v.receiver_mobile NOT LIKE '639%' AND v.receiver_mobile NOT LIKE '1639%'"),
            ],
            'gaming_funds' => [
                'label' => '*Gaming Funds report', 'dates' => 'range',
                'columns' => ['TransactionDate' => 'Transaction Date', 'CustomerName' => 'Customer Name', 'CustomerMobile' => 'Customer Mobile', 'CustomerEmail' => 'Customer Email', 'TotalAmount' => 'Total Amount'],
                'sql' => "SELECT ght.`created_date` AS TransactionDate, CONCAT(c.`first_name`, ' ', c.`last_name`) AS CustomerName, c.mobile AS CustomerMobile, c.email AS CustomerEmail, SUM(ght.amount) AS TotalAmount
                    FROM gaming_house_transaction_histories ght
                    INNER JOIN customers c ON (c.id = ght.customer_id)
                    INNER JOIN gaming_houses gc ON (gc.`code` = ght.`gaming_house_code`)
                    WHERE ght.transaction_type = 'Deposit' AND {day ght.created_date}
                    GROUP BY gc.`name`, c.first_name, c.last_name, c.mobile, DATE(ght.created_date) ORDER BY ght.`created_date` DESC LIMIT 10000000",
            ],
            'load_via_qr' => [
                'label' => '*Load Via QR', 'dates' => 'range',
                'columns' => ['TransactionDate' => 'Transaction Date', 'CustomerName' => 'Customer Name', 'TransactionType' => 'Transaction Type', 'Description' => 'Description', 'Amount' => 'Amount', 'Source' => 'Source'],
                'sql' => "SELECT CONCAT(cc.`first_name`, ' ', cc.`last_name`) AS CustomerName, trt.type AS TransactionType, et.`description` AS Description, et.`amount` AS Amount, et.`timestamp` AS TransactionDate,
                    CASE WHEN c.merchant_name = '' THEN c.dba_name WHEN c.dba_name = '' THEN c.legal_name ELSE c.merchant_name END AS Source
                    FROM ezkard_transactions et
                    INNER JOIN customers cc ON (cc.ezkard_account_id = et.`ezkard_id`)
                    LEFT JOIN transaction_types trt ON (trt.id = et.`trans_type_id`)
                    LEFT JOIN clients c ON (c.`id` = et.`merchant_id`)
                    WHERE et.description LIKE '%Suncash Load Via QR Code%' AND et.`timestamp` >= {from} AND et.`timestamp` < {to}
                    ORDER BY et.id DESC LIMIT 100000000",
            ],
            'payroll_credits' => [
                'label' => '*Payroll credits from Merchant to Customer Account Reports', 'dates' => 'range',
                'columns' => ['MerchantName' => 'Merchant Name', 'EmployeeName' => 'Employee Name', 'EmployeeMobile' => 'Employee Mobile', 'Amount' => 'Amount', 'transaction_date' => 'Transaction Date'],
                'sql' => 'SELECT '.self::BUSINESS_NAME.' AS `MerchantName`, pd.employee_name AS EmployeeName, pd.mobile AS EmployeeMobile, pd.amount AS Amount, pd.`timestamp` AS transaction_date
                    FROM payroll_details pd INNER JOIN payrolls p ON (pd.payroll_id = p.id) LEFT JOIN clients c ON (c.id = p.`employer_id`)
                    WHERE pd.payroll_push_result = 1 AND timestamp >= {from} AND timestamp < {to} ORDER BY pd.id ASC',
            ],
            'business_credits' => [
                'label' => '*Business credits from Merchant to Customer Account Reports', 'dates' => 'range', 'columns' => self::CREDITS_COLUMNS,
                'sql' => 'SELECT c.id, '.self::BUSINESS_NAME." AS Merchant_BusinessName, CONCAT(cc.first_name, ' ', cc.last_name) AS CustomerName, cc.mobile AS CustomerMobile,
                    et.description AS CustomerDescription, et.amount AS Amount, et.timestamp AS Transaction_date
                    FROM ezkard_transactions et
                    INNER JOIN clients c ON (c.id = et.`merchant_id`)
                    INNER JOIN customers cc ON (cc.ezkard_account_id = et.ezkard_id)
                    INNER JOIN transaction_types tr ON (tr.id = et.trans_type_id)
                    WHERE dba_name NOT LIKE '%Clearing%' AND dba_name NOT LIKE '%Test%' AND dba_name NOT IN ('PMSP', 'lemtest') AND c.id NOT IN (38, 37)
                    AND timestamp >= {from} AND timestamp < {to} AND tr.direction = 0 ORDER BY et.`timestamp` ASC",
            ],
            'all_vouchers_redeemed' => [
                'label' => '*All Vouchers Redeemed to the Clearing Account ', 'dates' => 'range',
                'columns' => ['VOUCHER_DATE' => 'Transaction Date', 'VOUCHER_NUMBER' => 'VOUCHER NUMBER', 'AMOUNT' => 'AMOUNT', 'MOBILE_NUMBER' => 'MOBILE NUMBER', 'EMAIL_ADDRESS' => 'EMAIL ADDRESS', 'PURCHASE_SOURCE' => 'PURCHASE SOURCE', 'REDEEMED_SOURCE' => 'REDEEMED SOURCE', 'REDEEMED_VOIDED_DATE' => 'REDEEMED VOIDED DATE', 'VOUCHER_TYPE' => 'VOUCHER TYPE'],
                'sql' => "(SELECT TIMESTAMP(v.voucher_date) AS VOUCHER_DATE, v.voucher_code AS VOUCHER_NUMBER, v.`amount` AS AMOUNT, v.`mobile` AS MOBILE_NUMBER, v.email AS EMAIL_ADDRESS,
                    CASE WHEN v.customer_owner_id > 0 THEN 'CustomerApp' WHEN w.id > 0 THEN 'WebPOS' WHEN v.merchant_owner_id > 0 THEN c2.dba_name ELSE IFNULL(c2.legal_name, IFNULL(bvg.field1, v.source)) END AS PURCHASE_SOURCE,
                    CASE WHEN v.remarks LIKE '%CustomerApp%' THEN 'CustomerApp' WHEN v.remarks LIKE '%CustomerPortal%' THEN 'CustomerPortal' WHEN c.legal_name = '' THEN c.dba_name
                    WHEN w2.id > 0 THEN CONCAT('WebPOS - ', cwm.legal_name) WHEN v.status = 'ACTIVE' THEN NULL ELSE c.legal_name END AS REDEEMED_SOURCE,
                    v.status AS STATUS, CASE WHEN v.status = 'ACTIVE' THEN NULL ELSE v.`update_date` END AS REDEEMED_VOIDED_DATE, 'SUNCASH VOUCHER' AS VOUCHER_TYPE
                    FROM merchant_vouchers v
                    LEFT JOIN clients c ON (c.id = v.client_record_id)
                    LEFT JOIN clients c2 ON (c2.id = v.merchant_owner_id)
                    LEFT JOIN customers cc ON (cc.mobile = v.mobile)
                    LEFT JOIN batch_voucher_generation bvg ON (bvg.voucher_number = v.`voucher_code`)
                    LEFT JOIN webpos_transaction w ON (w.reference = v.`voucher_code` AND w.transaction_type = 'VOUCHER' AND w.merchant_id NOT IN (38) AND w.status = 0)
                    LEFT JOIN webpos_transaction w2 ON (w2.reference = v.`voucher_code` AND w2.transaction_type = 'CASHOUT_VOUCHER' AND w2.merchant_id NOT IN (38) AND w2.status = 0)
                    LEFT JOIN clients cwm ON (cwm.id = w2.merchant_id)
                    WHERE v.update_date >= {from} AND v.update_date < {to} AND v.status = 'REDEEMED' AND v.mobile NOT LIKE '639%' AND v.mobile NOT LIKE '1639%' AND v.remarks IS NULL
                    GROUP BY v.voucher_code ORDER BY v.voucher_date ASC)
                    UNION ALL
                    (SELECT v.voucher_date AS VOUCHER_DATE, v.voucher_code AS VOUCHER_NUMBER, v.amount AS AMOUNT, receiver_mobile AS MOBILE_NUMBER, receiver_email AS EMAIL_ADDRESS,
                    CASE WHEN v.purchased_channel = '3rdParty' THEN ".self::clientName('v.purchased_client_id').' ELSE v.purchased_channel END AS PURCHASE_SOURCE,
                    CASE WHEN v.redeemed_channel = \'3rdParty\' THEN '.self::clientName('v.redeemed_client_id')." WHEN v.status = 'ACTIVE' THEN NULL
                    WHEN v.redeemed_channel = 'WebPOS' THEN CONCAT(v.redeemed_channel, ' - ', cwm.legal_name) ELSE v.redeemed_channel END AS REDEEMED_SOURCE,
                    v.STATUS, CASE WHEN v.status = 'ACTIVE' THEN NULL ELSE v.`update_date` END AS REDEEMED_VOIDED_DATE, 'UNIBUCKS VOUCHER' AS VOUCHER_TYPE
                    FROM universal_vouchers v
                    INNER JOIN universal_vouchers_logs uvl ON (uvl.universal_vouchers_id = v.id)
                    LEFT JOIN `webpos_transaction` w ON (w.reference = v.`voucher_code` AND w.transaction_type = 'VOUCHER' AND w.merchant_id NOT IN (38) AND w.status = 0)
                    LEFT JOIN `webpos_transaction` w2 ON (w2.reference = v.`voucher_code` AND w2.transaction_type = 'CASHOUT_VOUCHER' AND w2.merchant_id NOT IN (38) AND w2.status = 0)
                    LEFT JOIN clients cwm ON (cwm.id = w2.merchant_id)
                    WHERE v.update_date >= {from} AND v.update_date < {to} AND v.status = 'REDEEMED' AND v.receiver_mobile NOT LIKE '639%' AND v.receiver_mobile NOT LIKE '1639%' AND v.redeemed_channel IN ('WebPOS', '3rdParty')
                    GROUP BY v.voucher_code ORDER BY v.voucher_date ASC)
                    ORDER BY voucher_date ASC",
            ],
            'all_credits_business_merchant_from_customer' => [
                'label' => '*All Credits to Business Merchant Account from Customer Account Reports', 'dates' => 'range', 'columns' => self::CREDITS_COLUMNS,
                'sql' => 'SELECT c.id, '.self::BUSINESS_NAME." AS `Merchant_BusinessName`, CONCAT(cc.first_name, ' ', cc.last_name) AS CustomerName, cc.mobile AS CustomerMobile,
                    et.description AS CustomerDescription, et.amount AS Amount, et.timestamp AS Transaction_date
                    FROM ezkard_transactions et
                    INNER JOIN clients c ON (c.id = et.`merchant_id`)
                    INNER JOIN customers cc ON (cc.ezkard_account_id = et.ezkard_id)
                    INNER JOIN transaction_types tr ON (tr.id = et.trans_type_id)
                    WHERE dba_name NOT LIKE '%Clearing%' AND dba_name NOT LIKE '%Test%' AND dba_name NOT IN ('PMSP', 'lemtest') AND c.id NOT IN (38, 37)
                    AND et.`timestamp` >= {from} AND et.`timestamp` < {to} AND tr.direction = 1 ORDER BY et.`timestamp` ASC",
            ],
            'all_credits_business_merchant' => [
                'label' => '*All Credits to Business Merchant Account from Another Business Merchant Account Reports', 'dates' => 'range',
                'columns' => ['BusinessName' => 'Business Name', 'Description' => 'Description', 'Amount' => 'Amount', 'TransactionDate' => 'Transaction Date'],
                'sql' => 'SELECT '.self::BUSINESS_NAME." AS `BusinessName`, ct.description AS Description, ct.amount AS Amount, ct.timestamp AS TransactionDate
                    FROM client_transactions ct
                    INNER JOIN clients c ON (c.id = ct.`client_record_id`)
                    INNER JOIN transaction_types tr ON (tr.id = ct.trans_type_id)
                    WHERE dba_name NOT LIKE '%Clearing%' AND dba_name NOT LIKE '%Test%' AND dba_name NOT IN ('PMSP', 'lemtest') AND c.id NOT IN (38, 37)
                    AND ct.`timestamp` >= {from} AND ct.`timestamp` < {to} AND tr.id = 25 AND description LIKE '%Payment%' ORDER BY ct.`timestamp` ASC",
            ],
            'all_fees_witdrawn' => [
                'label' => '*All Fees Withdrawn from Merchant Account', 'dates' => 'range',
                'columns' => [...self::MANUAL_SETTLEMENT_COLUMNS, 'Fee' => 'Fee', 'Timestamp' => 'Transaction Date'],
                'sql' => 'SELECT '.self::BUSINESS_NAME." AS `Merchant_BusinessName`, ms.type AS TransactionType, ms.bank AS Bank, ms.bank_branch AS BankBranch, ms.fee AS Fee, ms.approved_date AS `Timestamp`
                    FROM manual_settlement ms INNER JOIN clients c ON (c.id = ms.`client_record_id`)
                    WHERE ms.approved_date >= {from} AND ms.approved_date < {to} AND STATUS = 'A' ORDER BY ms.id ASC",
            ],
            'all_bank_withdrawals' => [
                'label' => '*All Bank Withdrawals from Merchant Account', 'dates' => 'range',
                'columns' => [...self::MANUAL_SETTLEMENT_COLUMNS, 'Amount' => 'Amount', 'Timestamp' => 'Transaction Date'],
                'sql' => 'SELECT '.self::BUSINESS_NAME." AS `Merchant_BusinessName`, ms.type AS TransactionType, ms.bank AS Bank, ms.bank_branch AS BankBranch, ms.amount AS Amount, ms.approved_date AS `Timestamp`
                    FROM manual_settlement ms INNER JOIN clients c ON (c.id = ms.`client_record_id`)
                    WHERE ms.approved_date >= {from} AND ms.approved_date < {to} AND STATUS = 'A' ORDER BY ms.id ASC",
            ],
            'merchant_credited' => [
                'label' => '*Merchant Credited Prefunded', 'dates' => 'range',
                'columns' => ['MerchantName' => 'Merchant Name', 'TYPE' => 'Type', 'Amount' => 'Amount', 'Description' => 'Description', 'TransactionDate' => 'Transaction Date'],
                'sql' => 'SELECT '.self::BUSINESS_NAME." AS `MerchantName`, CASE WHEN c.reseller_type IN (5, 6) THEN 'Business' ELSE 'Merchant' END AS TYPE, ct.amount AS Amount, ct.description AS Description, ct.timestamp AS TransactionDate
                    FROM client_transactions ct INNER JOIN clients c ON (c.id = ct.`client_record_id`)
                    WHERE ct.description LIKE '%prefund balance credited%' AND `timestamp` >= {from} AND `timestamp` < {to} ORDER BY ct.id DESC",
            ],
            'void_transactions' => [
                'label' => '*Void Transactions Report', 'dates' => 'range',
                'columns' => ['TransactionDate' => 'Transaction Date', 'TransactionType' => 'Transaction Type', 'TransactionID' => 'Transaction ID', 'TerminalID' => 'Terminal ID', 'Amount' => 'Amount', 'Fee' => 'Fee', 'TotalAmount' => 'Total Amount', 'merchant_name' => 'Merchant Name', 'CashierName' => 'Cashier Name'],
                'sql' => "SELECT w.`transaction_date` AS TransactionDate, CONCAT('VOID ', w.transaction_type) AS TransactionType, w.`transaction_id` AS TransactionID, terminal_id AS TerminalID, w.amount AS Amount,
                    (w.`fee_amount` + w.vat_amount) AS Fee, (w.`amount` + w.`fee_amount` + w.vat_amount) AS TotalAmount, c.legal_name AS merchant_name, CONCAT(u.first_name, ' ', u.last_name) AS CashierName
                    FROM webpos_transaction w
                    INNER JOIN clients c ON c.id = w.merchant_id
                    INNER JOIN merchant_terminal_users u ON u.id = w.terminal_user_id
                    WHERE w.status = 1 AND {day_ge w.transaction_date} AND {day_lt w.transaction_date} ORDER BY transaction_date",
            ],
            'cashout_via_access' => [
                'label' => '*Cash out via Access Token report', 'dates' => 'range',
                'columns' => ['CustomerName' => 'Customer Name', 'CustomerMobile' => 'Customer Mobile', 'TransactionId' => 'Transaction ID', 'Amount' => 'Amount', 'TransactionType' => 'Transaction Type', 'TransactionType2' => 'Transaction Type2', 'Description' => 'Description', 'TransactionDate' => 'Transaction Date'],
                'sql' => "SELECT CONCAT(c.first_name, ' ', c.last_name) CustomerName, c.mobile AS CustomerMobile, ezt.transaction_id AS TransactionId, ezt.amount AS Amount,
                    CASE WHEN ty.direction = 1 THEN 'DEBIT' ELSE 'CREDIT' END AS TransactionType, ty.type AS TransactionType2, ezt.description AS Description, ezt.timestamp AS TransactionDate
                    FROM ezkard_transactions ezt
                    INNER JOIN ezkard_accounts eza ON (eza.id = ezt.ezkard_id)
                    INNER JOIN customers c ON (c.mobile = eza.mobile_number)
                    INNER JOIN transaction_types ty ON (ty.id = ezt.trans_type_id)
                    WHERE ezt.timestamp >= {from} AND ezt.timestamp < {to_next} AND ezt.`ezkard_id` > 0 AND description like '%Cashout Via%' AND c.status = 'A'
                    AND c.mobile NOT LIKE '639%' AND c.mobile NOT LIKE '1639%' AND ezt.trans_status_id = 0 AND ty.direction IN (1, 0) ORDER BY ezt.timestamp",
            ],
            'load_via_card' => [
                'label' => '*Load Via Card Fee Report', 'dates' => 'range',
                'columns' => ['CustomerName' => 'Customer Name', 'CustomerMobile' => 'Customer Mobile', 'TransactionId' => 'Transaction ID', 'Amount' => 'Amount', 'TransactionType' => 'Transaction Type', 'TransactionType2' => 'Transaction Type2', 'Description' => 'Description', 'TransactionDate' => 'Transaction Date'],
                'sql' => "SELECT CONCAT(c.first_name, ' ', c.last_name) CustomerName, c.mobile AS CustomerMobile, ezt.transaction_id AS TransactionId, ezt.amount AS Amount,
                    CASE WHEN ty.direction = 1 THEN 'DEBIT' ELSE 'CREDIT' END AS TransactionType, ty.type AS TransactionType2, ezt.description AS Description, ezt.timestamp AS TransactionDate
                    FROM ezkard_transactions ezt
                    INNER JOIN ezkard_accounts eza ON (eza.id = ezt.ezkard_id)
                    INNER JOIN customers c ON (c.mobile = eza.mobile_number)
                    INNER JOIN transaction_types ty ON (ty.id = ezt.trans_type_id)
                    WHERE ezt.timestamp >= {from} AND ezt.timestamp < {to_next} AND ezt.`ezkard_id` > 0 AND ezt.description LIKE 'Load via Card - FEE%' AND c.status = 'A'
                    AND c.mobile NOT LIKE '639%' AND c.mobile NOT LIKE '1639%' AND ezt.trans_status_id = 0 AND ty.direction IN (1, 0) ORDER BY ezt.timestamp LIMIT 1000000",
            ],
            'gaming_fee' => [
                'label' => '*Gaming Funds Fee Report', 'dates' => 'range', 'columns' => self::ledgerColumns('TransactionID', 'Transaction ID'),
                'sql' => '('.self::ledger('TransactionID', "AND ezt.description LIKE 'Gaming Funds Deposit Fee -%' AND c.status = 'A'", 'asc').') UNION ALL ('.self::gamingFeeHistory('TransactionID').') ORDER BY TransactionDate ASC',
            ],
            'wu_transactions' => [
                'label' => '*Wu Online Transactions Report', 'dates' => 'range',
                'columns' => ['CustomerName' => 'Customer Name', 'CustomerMobile' => 'Customer Mobile', 'SenderName' => 'Sender Name', 'ReceiverName' => 'Receiver Name', 'TransactionType' => 'Transaction Type', 'Description' => 'Description', 'Amount' => 'Amount', 'TransactionFee' => 'Transaction Fee', 'SendingFee' => 'Sending Fee', 'VAT' => 'VAT', 'MTCN' => 'MTCN', 'TransactionDate' => 'TransactionDate'],
                'sql' => "SELECT CONCAT(c.`first_name`, ' ', c.`last_name`) AS CustomerName, c.`mobile` AS CustomerMobile, CONCAT(cth.`sender_firstname`, ' ', cth.`sender_lastname`) AS SenderName,
                    CONCAT(cth.`receiver_firstname`, ' ', cth.`receiver_lastname`) AS ReceiverName, cth.`finance_orientation` AS TransactionType, cth.`description` AS Description,
                    cth.`amount` AS Amount, cth.`transaction_fee` AS TransactionFee, cth.`sending_fee` AS SendingFee, cth.`vat` AS VAT, cth.`ref_id` AS MTCN, cth.`created_date` AS TransactionDate
                    FROM customer_transaction_histories cth INNER JOIN customers c ON c.id = cth.`customer_id`
                    WHERE cth.`transaction_type` = 'MONEYTRANSFER_WU' AND cth.status = 'PAID'
                    AND cth.`description` IN ('Westernunion Money Transfer (SEND)', 'Westernunion Money Transfer (RECEIVE)') AND cth.`is_to_display` = 1
                    AND cth.`created_date` >= {from} AND cth.`created_date` < {to_next} LIMIT 10000000",
            ],
        ];
    }
}
