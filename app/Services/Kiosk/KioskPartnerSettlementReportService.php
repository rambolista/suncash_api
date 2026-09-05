<?php

namespace App\Services\Kiosk;

use App\Models\Mysuncash\KioskBranch;
use Illuminate\Support\Facades\DB;

/**
 * "Kiosk > Reports > Partner Settlement" tab (legacy
 * `fastpay::partner_settlement_report()` / `tp_settlement_report_filter()` /
 * `Fastpay_kiosk_model::getSettlementReport()`).
 *
 * Distinct from every other Kiosk report: it's scoped to third-party
 * "partner" branches only (`kiosk_branch.is_tp = '1'`) — the terminal's
 * OWN branch, not the transaction's `branch_id` (that's only used as an
 * optional narrower filter, exactly matching legacy). Legacy's SQL is a
 * 3-way `UNION ALL` (main + SUNCASH_VOUCHER + UNIBUCKS_VOUCHER — no
 * CREDIT_VOUCHER arm, unlike the Transaction report), parameter-bound here
 * instead of legacy's raw string-interpolated `date_from`/`date_to`/
 * `branch_id` (a real SQL-injection surface, not replicated). Legacy's own
 * `$where`/`$where_tup`/`$where_v` transaction_type-narrowing blocks are NOT
 * ported: the UI never exposes a transaction-type filter for this report
 * (only Partner + Date range), so that code path is dead in practice.
 *
 * Each row's per-terminal commission uses `KioskCommissionMath::computeCommissionTimedate()`
 * (NOT the plain `computeCommission()` used by every other commission-bearing
 * report) — this is the ONE Kiosk report where the "Fixed" commission leg is
 * non-zero, scaled by the number of full months the date range spans
 * (`commissionMonths()`, legacy `getCommissionMonths()`). For a same-day/
 * sub-month range this is 0, so only percentage-type commission shows —
 * this is legacy's own behavior, not a bug.
 *
 * "Commission" here is only the AGENT leg — legacy's own report never shows
 * or accumulates the suncash/owner legs for this tab, matching
 * `computeCommissionTimedate()`'s call site exactly. For commission_type
 * 1 (Fixed) and 4 (Fixed+Percentage), and for 3 (Greater Amount) ONLY when
 * no percentage profile exists for that terminal/product (so the Fixed leg
 * is all there is), legacy adds the Fixed-leg commission ONCE per terminal
 * for the whole date range (not once per transaction — `$addedFixedCommission`
 * dedup guard) — replicated exactly, since re-adding a flat monthly fee per
 * transaction would inflate it by the transaction count.
 */
class KioskPartnerSettlementReportService
{
    /** legacy `settings::$kiosk_excluded_merchants` */
    private const EXCLUDED_MERCHANT_IDS = [303];

    public function __construct(private readonly KioskCommissionMath $math) {}

    /** Legacy `get_kiosk_branches('', true)` — partner branches only, for the "Partner" filter dropdown. */
    public function listPartners(): array
    {
        return KioskBranch::where('status', KioskBranch::STATUS_ACTIVE)
            ->where('is_tp', 1)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->all();
    }

    /** Legacy `Kiosk_commission_model::calculate_fee()` — fetches all KIOSK_CARD_WITHDRAWAL/Kiosk fee bands once, first `amount_to > 0` band match wins. */
    private function cardWithdrawalFeeBands(): array
    {
        return DB::connection('mysuncash')->table('fees')
            ->where('fee_type', 'KIOSK_CARD_WITHDRAWAL')
            ->where('channel', 'Kiosk')
            ->get(['amount_from', 'amount_to', 'fixed'])
            ->all();
    }

    private function cardWithdrawalFee(array $bands, float $amount): float
    {
        foreach ($bands as $band) {
            if ((float) $band->amount_to > 0 && $amount >= (float) $band->amount_from && $amount <= (float) $band->amount_to) {
                return (float) $band->fixed;
            }
        }

        return 0.0;
    }

    /** Legacy `is_trans_type_debit_credit()`. */
    private function isDebit(string $transactionType): bool
    {
        return in_array($transactionType, [
            'CASHOUT', 'GAMINGHOUSE_WITHDRAWAL', 'WITHDRAW_SANDDOLLAR',
            'CARD_WITHDRAWAL', 'GAMINGHOUSE_WITHDRAW', 'BUSINESS_BANK_WITHDRAWAL',
        ], true);
    }

    /**
     * Legacy `is_tp_deposit_withdraw()` — fuzzy-matches a gaming-house
     * transaction's `gh_type` code (e.g. "AW", "PG") against the terminal's
     * partner branch name (a couple of names are aliased first: "Asurewin"
     * -> "aw", "PGWeb"/"paradise games" -> "pg"), via case-insensitive
     * substring search. Replicated as-is, fragile aliasing included.
     *
     * @return array{deposit: float, withdraw: float}
     */
    private function partnerDepositWithdraw(?string $ghType, string $transactionType, ?string $branchName, float $amount): array
    {
        $response = ['deposit' => 0.0, 'withdraw' => 0.0];
        if (! in_array($transactionType, ['GAMINGHOUSE_DEPOSIT', 'GAMINGHOUSE_WITHDRAWAL'], true)) {
            return $response;
        }

        $branchName = (string) $branchName;
        if (strtolower($branchName) === 'asurewin') {
            $branchName = 'aw';
        } elseif (in_array(strtolower($branchName), ['pgweb', 'paradise games'], true)) {
            $branchName = 'pg';
        }

        if ($transactionType === 'GAMINGHOUSE_DEPOSIT' && $ghType !== null && stripos($branchName, $ghType) !== false) {
            $response['deposit'] = $amount;
        } elseif ($transactionType === 'GAMINGHOUSE_WITHDRAWAL' && $ghType !== null && stripos($branchName, $ghType) !== false) {
            $response['withdraw'] = $amount;
        }

        return $response;
    }

    /** Legacy `getCommissionMonths()` — full months spanned by the range (inclusive of a partial trailing day), 0 for same-day/sub-month ranges. */
    private function commissionMonths(string $dateFrom, string $dateTo): int
    {
        $from = strtotime($dateFrom);
        $to = strtotime($dateTo);
        if ($from === false || $to === false || $to < $from) {
            return 0;
        }

        $months = 0;
        $cursor = $from;
        $toInclusive = strtotime('+1 day', $to);
        while (strtotime('+1 month', $cursor) <= $toInclusive) {
            $months++;
            $cursor = strtotime('+1 month', $cursor);
        }

        return $months;
    }

    private function unionSql(): string
    {
        $excluded = implode(',', self::EXCLUDED_MERCHANT_IDS);

        return <<<SQL
            SELECT
                CASE
                    WHEN wtk.transaction_type = 'BILLPAY' THEN bt.biller_code
                    WHEN wtk.transaction_type = 'KioskTopup' AND mtt.provider = 'aliv' THEN 'ALIV_TOPUP'
                    WHEN wtk.transaction_type = 'KioskTopup' AND mtt.provider = 'emida' THEN 'BTC_TOPUP'
                    WHEN wtk.transaction_type = 'KioskTopup' AND mtt.provider = 'paynation' THEN 'INTERNATIONAL_TOPUP'
                    ELSE wtk.transaction_type
                END AS transaction_type,
                ktl.name AS terminal, i.name AS island, ktl.location,
                wtk.amount, wtk.fee_amount, wtk.vat_amount, wtk.total_amount,
                wtk.terminal_id, wtk.gh_type, kb.name AS terminal_branch, wtk.branch_id,
                ktl.commission_fixed_value, ktl.commission_type AS comm_type
            FROM webpos_transaction_kiosk wtk
            INNER JOIN clients cl ON cl.id = wtk.merchant_id
            LEFT JOIN mobile_topup_transactions mtt ON mtt.id = wtk.reference_id AND wtk.transaction_type = 'KioskTopup'
            LEFT JOIN billpay_transactions bt ON bt.settlement_transaction_id = wtk.transaction_id AND wtk.transaction_type = 'BILLPAY'
            INNER JOIN kiosk_terminal ktl ON ktl.id = wtk.terminal_id
            INNER JOIN kiosk_branch kb ON kb.id = ktl.kiosk_branch_id AND kb.is_tp = '1'
            LEFT JOIN island i ON i.id = ktl.island
            WHERE wtk.transaction_date >= ? AND wtk.transaction_date <= ?
                AND wtk.status = 0
                AND wtk.transaction_type NOT IN ('VOUCHER')
                AND wtk.merchant_id NOT IN ({$excluded})

            UNION ALL

            SELECT
                'SUNCASH_VOUCHER' AS transaction_type,
                ktl.name AS terminal, i.name AS island, ktl.location,
                wtk.amount, wtk.fee_amount, wtk.vat_amount, wtk.total_amount,
                wtk.terminal_id, wtk.gh_type, kb.name AS terminal_branch, wtk.branch_id,
                ktl.commission_fixed_value, ktl.commission_type AS comm_type
            FROM webpos_transaction_kiosk wtk
            INNER JOIN clients cl ON cl.id = wtk.merchant_id
            INNER JOIN merchant_vouchers mv ON mv.voucher_code = wtk.trans_ref_id AND wtk.transaction_type = 'VOUCHER'
            INNER JOIN kiosk_terminal ktl ON ktl.id = wtk.terminal_id
            INNER JOIN kiosk_branch kb ON kb.id = ktl.kiosk_branch_id AND kb.is_tp = '1'
            LEFT JOIN island i ON i.id = ktl.island
            WHERE wtk.status = 0
                AND wtk.transaction_date >= ? AND wtk.transaction_date <= ?
                AND (wtk.trans_ref_id != '' AND wtk.trans_ref_id != '-1')
                AND wtk.merchant_id NOT IN ({$excluded})

            UNION ALL

            SELECT
                'UNIBUCKS_VOUCHER' AS transaction_type,
                ktl.name AS terminal, i.name AS island, ktl.location,
                wtk.amount, wtk.fee_amount, wtk.vat_amount, wtk.total_amount,
                wtk.terminal_id, wtk.gh_type, kb.name AS terminal_branch, wtk.branch_id,
                ktl.commission_fixed_value, ktl.commission_type AS comm_type
            FROM webpos_transaction_kiosk wtk
            INNER JOIN clients cl ON cl.id = wtk.merchant_id
            INNER JOIN universal_vouchers mv ON mv.voucher_code = wtk.trans_ref_id AND wtk.transaction_type = 'VOUCHER'
            INNER JOIN kiosk_terminal ktl ON ktl.id = wtk.terminal_id
            INNER JOIN kiosk_branch kb ON kb.id = ktl.kiosk_branch_id AND kb.is_tp = '1'
            LEFT JOIN island i ON i.id = ktl.island
            WHERE wtk.status = 0
                AND mv.voucher_product_id != 3
                AND wtk.transaction_date >= ? AND wtk.transaction_date <= ?
                AND (wtk.trans_ref_id != '' AND wtk.trans_ref_id != '-1')
                AND wtk.merchant_id NOT IN ({$excluded})
            SQL;
    }

    public function list(string $dateFrom, string $dateTo, ?int $branchId = null): array
    {
        $dateFromTs = "{$dateFrom} 00:00:00";
        $dateToTs = "{$dateTo} 23:59:59";
        $bindings = array_merge(...array_fill(0, 3, [$dateFromTs, $dateToTs]));

        $sql = 'SELECT * FROM ('.$this->unionSql().') AS sql_all';
        if ($branchId) {
            $sql .= ' WHERE branch_id = ?';
            $bindings[] = $branchId;
        }

        $transactions = DB::connection('mysuncash')->select($sql, $bindings);

        $months = $this->commissionMonths($dateFrom, $dateTo);
        $isMonthly = $months >= 1;

        $cardWithdrawalBands = null;
        $terminalCache = [];
        $profileCache = [];
        $addedFixedCommission = [];

        $terminalData = [];
        foreach ($transactions as $row) {
            $terminalId = (int) $row->terminal_id;
            $transactionType = $row->transaction_type === 'LOAD_MOBILEWALLET_SANDDOLLAR' ? 'LOAD_SANDDOLLAR' : $row->transaction_type;

            $feeAmount = (float) $row->fee_amount;
            if ($transactionType === 'CARD_WITHDRAWAL') {
                $cardWithdrawalBands ??= $this->cardWithdrawalFeeBands();
                $feeAmount = $this->cardWithdrawalFee($cardWithdrawalBands, (float) $row->amount);
            }

            $dispensed = $this->isDebit($transactionType) ? (float) $row->total_amount : 0.0;
            $collected = ! $this->isDebit($transactionType) ? (float) $row->total_amount : 0.0;
            $partner = $this->partnerDepositWithdraw($row->gh_type, $transactionType, $row->terminal_branch, (float) $row->total_amount);

            $cashCollected = $collected;
            $cashDispensed = -$dispensed;
            $partnerDeposits = -$partner['deposit'];
            $partnerWithdrawals = $partner['withdraw'];
            $totalFees = $feeAmount;
            $totalVat = (float) $row->vat_amount;

            $commission = $this->math->computeCommissionTimedate($terminalCache, $profileCache, $transactionType, $terminalId, (float) $row->amount, $feeAmount, $months);
            $agentCommission = $commission['agent_commission'];

            if (! isset($terminalData[$terminalId])) {
                $terminalData[$terminalId] = [
                    'partner' => $row->terminal_branch,
                    'kiosk' => $row->terminal,
                    'location' => $row->location ?: 'UNASSIGNED',
                    'island' => $row->island ?: 'UNASSIGNED',
                    'total_fees' => 0.0,
                    'total_vat' => 0.0,
                    'cash_collected' => 0.0,
                    'cash_dispensed' => 0.0,
                    'commission' => 0.0,
                    'partner_deposits' => 0.0,
                    'partner_withdrawals' => 0.0,
                    'transaction_count' => 0,
                ];
            }

            $terminalData[$terminalId]['total_fees'] += $totalFees;
            $terminalData[$terminalId]['total_vat'] += $totalVat;
            $terminalData[$terminalId]['cash_collected'] += $cashCollected;
            $terminalData[$terminalId]['cash_dispensed'] += $cashDispensed;
            $terminalData[$terminalId]['partner_deposits'] += $partnerDeposits;
            $terminalData[$terminalId]['partner_withdrawals'] += $partnerWithdrawals;
            $terminalData[$terminalId]['transaction_count']++;

            // Fixed-leg commission (comm_type 1, 4, or 3-with-no-percentage-profile) is
            // added ONCE per terminal for the whole range, not once per transaction.
            $addFixedOnce = $isMonthly && (
                (int) $row->comm_type === 1
                || (int) $row->comm_type === 4
                || ((int) $row->comm_type === 3 && $commission['commission_type'] === 'fixed')
            );

            if ($addFixedOnce) {
                if (! isset($addedFixedCommission[$terminalId])) {
                    $terminalData[$terminalId]['commission'] += $agentCommission;
                    $addedFixedCommission[$terminalId] = true;
                }
            } else {
                $terminalData[$terminalId]['commission'] += $agentCommission;
            }
        }

        $rows = [];
        foreach ($terminalData as $data) {
            $netSettlement = $data['cash_collected'] + $data['cash_dispensed'] + $data['partner_deposits']
                + $data['partner_withdrawals'] + $data['total_fees'] + $data['total_vat'];

            $rows[] = [
                'partner' => $data['partner'],
                'kiosk' => $data['kiosk'],
                'location' => $data['location'],
                'island' => $data['island'],
                'cash_collected' => round($data['cash_collected'], 2),
                'cash_dispensed' => round($data['cash_dispensed'], 2),
                'partner_deposits' => round($data['partner_deposits'], 2),
                'partner_withdrawals' => round($data['partner_withdrawals'], 2),
                'total_fees' => round($data['total_fees'], 2),
                'total_vat' => round($data['total_vat'], 2),
                'commission' => round($data['commission'], 2),
                'net_settlement' => round($netSettlement, 2),
                'transaction_count' => $data['transaction_count'],
            ];
        }

        $totals = [
            'total_cash_collected' => 0.0,
            'total_cash_dispensed' => 0.0,
            'total_partner_deposits' => 0.0,
            'total_partner_withdrawals' => 0.0,
            'total_fees' => 0.0,
            'total_vat' => 0.0,
            'total_commission' => 0.0,
            'total_net_settlement' => 0.0,
            'total_transaction_count' => 0,
        ];
        foreach ($rows as $row) {
            $totals['total_cash_collected'] += $row['cash_collected'];
            $totals['total_cash_dispensed'] += $row['cash_dispensed'];
            $totals['total_partner_deposits'] += $row['partner_deposits'];
            $totals['total_partner_withdrawals'] += $row['partner_withdrawals'];
            $totals['total_fees'] += $row['total_fees'];
            $totals['total_vat'] += $row['total_vat'];
            $totals['total_commission'] += $row['commission'];
            $totals['total_net_settlement'] += $row['net_settlement'];
            $totals['total_transaction_count'] += $row['transaction_count'];
        }

        // Legacy "Total Summary": Cash-Out/Deposits/Withdrawals/Net Settlement are
        // displayed as absolute values here (per-row values above stay signed, for
        // the red/negative styling), and "Principal Due to SunCash" is derived from
        // those already-abs'd totals — replicated with plain floats instead of
        // legacy's sprintf("%.2f", ...) string round-tripping (same result).
        $absDispensed = abs($totals['total_cash_dispensed']);
        $absDeposits = abs($totals['total_partner_deposits']);
        $absWithdrawals = abs($totals['total_partner_withdrawals']);
        $absNetSettlement = abs($totals['total_net_settlement']);
        $principalDueToSuncash = ($totals['total_cash_collected'] - ($absDispensed + $absDeposits)) + $absWithdrawals;

        $totals['total_cash_dispensed'] = round($absDispensed, 2);
        $totals['total_partner_deposits'] = round($absDeposits, 2);
        $totals['total_partner_withdrawals'] = round($absWithdrawals, 2);
        $totals['total_net_settlement'] = round($absNetSettlement, 2);
        $totals['total_cash_collected'] = round($totals['total_cash_collected'], 2);
        $totals['total_fees'] = round($totals['total_fees'], 2);
        $totals['total_vat'] = round($totals['total_vat'], 2);
        $totals['total_commission'] = round($totals['total_commission'], 2);
        $totals['principal_due_to_suncash'] = round($principalDueToSuncash, 2);

        return ['rows' => $rows, 'totals' => $totals];
    }
}
