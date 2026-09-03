<?php

namespace App\Services\Kiosk;

use App\Models\Mysuncash\Island;
use App\Models\Mysuncash\KioskTerminal;
use Illuminate\Support\Facades\DB;

/**
 * "Kiosk > Reports > Reconciliation" tab (legacy
 * `fastpay::kiosk_reconciliation_report()` / `kiosk_recon_report_filter()` /
 * `Fastpay_kiosk_model::generate_reconciliation_report()`).
 *
 * Unlike every other Kiosk report tab, this one is NOT derived from
 * `KioskTransactionReportService`'s row set — legacy queries
 * `kiosk_cash_meters_trx` (raw hardware meter snapshots) and
 * `kiosk_meters_user` (REPLENISH / PRINT_CLEAR meter-clear events) directly,
 * in addition to `webpos_transaction_kiosk`, to reconcile each terminal's
 * cash MOVEMENT (not just its transactions) over a date range:
 *
 *   Net Balance = (Cash In + Cash Loaded + Credit Adjustments)
 *               − (Cash Out + Deposits + Debit Adjustments)
 *               + Balance B/F (opening balance, one day before the range)
 *
 * "Kiosk" here displays `kiosk_terminal.name` (not `.code`, unlike every
 * other ported report's terminal column) — replicated as-is, since that's
 * literally what legacy's own SQL selects for this report specifically.
 *
 * Legacy bug NOT replicated (confirmed benign): `get_kiosk_total_cash_loaded_or_deposit()`
 * writes `if ($type = "in") ...` / `if ($type = "out") ...` — assignment,
 * not comparison — so both branches always technically "fire". In practice
 * this doesn't corrupt any actually-used output: each call site only reads
 * ONE key (`total_cash_loaded` for an `"in"` call, `total_deposits` for an
 * `"out"` call), and that specific key is computed correctly regardless of
 * the bug, because the underlying SQL was already filtered by the caller's
 * real `$type` before the assignment mistake happens. The *other* key each
 * call leaves polluted is simply never read. Implemented here with a plain
 * `===` check per call, producing identical output without the confusing
 * double-assignment.
 */
class KioskReconciliationReportService
{
    public function listTerminals(): array
    {
        return KioskTerminal::orderBy('name')->get(['id', 'name'])->all();
    }

    public function listIslands(): array
    {
        return Island::orderBy('name')->get(['id', 'code', 'name'])->all();
    }

    /** Legacy `calculate_total_dispense()` — cassette `Identifier` maps to a `Denomination`; sums `Denomination * count` per denomination once. */
    private function calculateTotalDispense(?string $data, ?string $cassetteJson): float
    {
        $counts = json_decode((string) $data, true);
        $cassettes = json_decode((string) $cassetteJson, true);
        if (! is_array($counts) || ! is_array($cassettes) || $cassettes === []) {
            return 0.0;
        }

        $total = 0.0;
        $processed = [];
        foreach ($counts as $key => $count) {
            foreach ($cassettes as $cassette) {
                $denom = $cassette['Denomination'] ?? null;
                $identifier = $cassette['Identifier'] ?? null;
                if ($denom === null || $identifier === null || in_array($denom, $processed, true)) {
                    continue;
                }
                if (str_contains((string) $key, "BILL_DISPENSER_CURRENT_{$identifier}")) {
                    $processed[] = $denom;
                    $total += $denom * $count;
                }
            }
        }

        return $total;
    }

    /** Legacy `calculate_total_acceptor()` — reads `BILL_ACCEPTOR_CURRENT_<denom>` keys directly (denomination is parsed from the key itself, not a separate cassette map). */
    private function calculateTotalAcceptor(?string $data): float
    {
        $counts = json_decode((string) $data, true);
        if (! is_array($counts) || $counts === []) {
            return 0.0;
        }

        $total = 0.0;
        $processed = [];
        foreach ($counts as $key => $count) {
            if (preg_match('/^BILL_ACCEPTOR_CURRENT_(100|\d{1,2})$/', (string) $key, $m)) {
                $denom = $m[1];
                if (in_array($denom, $processed, true)) {
                    continue;
                }
                $processed[] = $denom;
                $total += (int) $denom * $count;
            }
        }

        return $total;
    }

    /**
     * Legacy `get_kiosk_balance()` — opening balance, summed across the day
     * BEFORE `dateFrom` through the day before `dateTo`. Does the JSON
     * extraction in PHP rather than SQL (unlike legacy's raw
     * `JSON_EXTRACT(...)`): some stored `data` rows aren't valid JSON, and
     * MySQL's `JSON_EXTRACT` errors on a malformed document even inside an
     * `IF()` branch that wouldn't otherwise be taken, whereas PHP's
     * `json_decode()` just returns null. Same values for well-formed rows.
     */
    private function runningBalance(string $dateFrom, string $dateTo, int $terminalId): float
    {
        $rows = DB::connection('mysuncash')->table('kiosk_cash_meters_trx')
            ->where('terminal_id', $terminalId)
            ->whereBetween(DB::raw('DATE(timestamp)'), [
                DB::raw("DATE_SUB('{$dateFrom}', INTERVAL 1 DAY)"),
                DB::raw("DATE_SUB('{$dateTo}', INTERVAL 1 DAY)"),
            ])
            ->groupBy(DB::raw('DATE(timestamp)'))
            ->get(['type', 'data', 'dispense_cassette']);

        $total = 0.0;
        foreach ($rows as $row) {
            if ($row->type === 'out') {
                $total += $this->calculateTotalDispense($row->data, $row->dispense_cassette);

                continue;
            }

            $decoded = json_decode((string) $row->data, true);
            $total += (float) ($decoded['BILL_ACCEPTOR_CURRENT_VALUE'] ?? 0);
        }

        return $total;
    }

    /** Legacy `get_kiosk_total_cash_loaded_or_deposit()` — latest meter row of the given hardware `type` ('in'/'out') within the range. */
    private function cashInOrOut(string $dateFrom, string $dateTo, int $terminalId, string $type): float
    {
        $row = DB::connection('mysuncash')->table('kiosk_cash_meters_trx')
            ->where('type', $type)
            ->where('terminal_id', $terminalId)
            ->whereBetween(DB::raw('DATE(timestamp)'), [$dateFrom, $dateTo])
            ->orderByDesc('id')
            ->first(['data', 'dispense_cassette']);

        if (! $row) {
            return 0.0;
        }

        if ($type === 'in') {
            $decoded = json_decode((string) $row->data, true);

            return (float) ($decoded['BILL_ACCEPTOR_CURRENT_VALUE'] ?? 0);
        }

        return $this->calculateTotalDispense($row->data, $row->dispense_cassette);
    }

    /**
     * Legacy `get_kiosk_total_cash_loaded()` — REPLENISH ("out", cash
     * loaded) or PRINT_CLEAR ("in", deposits) meter-clear events within the
     * range. Legacy's SQL (replicated verbatim, including the two-level
     * `MAX()`/`GROUP BY`) doesn't call any JSON function itself — only the
     * PHP-side `calculate_total_dispense()`/`calculate_total_acceptor()`
     * decode the resulting blob — so unlike `runningBalance()` there's no
     * malformed-JSON crash risk in doing this one in SQL.
     */
    private function cashLoadedOrDeposits(string $dateFrom, string $dateTo, int $terminalId, string $category): float
    {
        $categorySql = $category === 'REPLENISH' ? 'kmu.category = ?' : 'kmu.category LIKE ?';
        $categoryParam = $category === 'REPLENISH' ? $category : "%{$category}%";

        $row = DB::connection('mysuncash')->selectOne(
            "SELECT MAX(total_cash_in) AS total_cash_in, MAX(total_cash_in_count) AS total_cash_in_count, type
            FROM (
                SELECT kmu.terminal_id, kmu.type, MAX(kmu.data) as total_cash_in, MAX(kmu.dispense_cassette) as total_cash_in_count
                FROM kiosk_meters_user kmu
                WHERE {$categorySql}
                    AND kmu.terminal_id = ?
                    AND DATE(kmu.timestamp) BETWEEN ? AND ?
                GROUP BY DATE(kmu.timestamp)
            ) AS terminal_totals
            GROUP BY terminal_id",
            [$categoryParam, $terminalId, $dateFrom, $dateTo]
        );

        if (! $row) {
            return 0.0;
        }

        return $row->type === 'out'
            ? $this->calculateTotalDispense($row->total_cash_in, $row->total_cash_in_count)
            : $this->calculateTotalAcceptor($row->total_cash_in);
    }

    /** Legacy `get_kiosk_total_fee_vat()`. */
    private function feeVat(string $dateFrom, string $dateTo, int $terminalId): array
    {
        $row = DB::connection('mysuncash')->table('webpos_transaction_kiosk as wtk')
            ->where('wtk.status', '0')
            ->where('wtk.terminal_id', $terminalId)
            ->whereBetween(DB::raw('DATE(wtk.transaction_date)'), [$dateFrom, $dateTo])
            ->selectRaw('IFNULL(SUM(wtk.fee_amount), 0) as total_fee, IFNULL(SUM(wtk.vat_amount), 0) as total_vat')
            ->first();

        return ['total_fee' => (float) $row->total_fee, 'total_vat' => (float) $row->total_vat];
    }

    /** Legacy `get_kiosk_adjustment_transactions()` — manual credit/debit float adjustments logged against a terminal. */
    private function adjustment(string $dateFrom, string $dateTo, int $terminalId, string $type): float
    {
        $total = DB::connection('mysuncash')->table('kiosk_terminal_transactions')
            ->where('terminal_id', $terminalId)
            ->where('trans_type', $type)
            ->whereBetween('create_date', ["{$dateFrom} 00:00:00", "{$dateTo} 23:59:59"])
            ->sum('amount');

        return (float) $total;
    }

    public function list(string $dateFrom, string $dateTo, ?int $terminalId = null, ?int $islandId = null): array
    {
        $query = DB::connection('mysuncash')->table('webpos_transaction_kiosk as wtk')
            ->join('kiosk_terminal as kt', 'kt.id', '=', 'wtk.terminal_id')
            ->leftJoin('island as i', 'i.id', '=', 'kt.island')
            ->where('wtk.status', '0')
            ->whereBetween(DB::raw('DATE(wtk.transaction_date)'), [$dateFrom, $dateTo]);

        if ($terminalId) {
            $query->where('wtk.terminal_id', $terminalId);
        }
        if ($islandId) {
            $query->where('kt.island', $islandId);
        }

        $terminals = $query->groupBy('wtk.terminal_id')
            ->get(['wtk.terminal_id', 'i.name as island', 'kt.name as kiosk', 'kt.location']);

        $rows = [];
        foreach ($terminals as $terminal) {
            $tid = (int) $terminal->terminal_id;

            $runningBalance = $this->runningBalance($dateFrom, $dateTo, $tid);
            $cashIn = $this->cashInOrOut($dateFrom, $dateTo, $tid, 'in');
            $cashOut = $this->cashInOrOut($dateFrom, $dateTo, $tid, 'out');
            $deposits = $this->cashLoadedOrDeposits($dateFrom, $dateTo, $tid, 'PRINT_CLEAR');
            $cashLoaded = $this->cashLoadedOrDeposits($dateFrom, $dateTo, $tid, 'REPLENISH');
            $fees = $this->feeVat($dateFrom, $dateTo, $tid);
            $creditAdjustments = $this->adjustment($dateFrom, $dateTo, $tid, 'credit');
            $debitAdjustments = $this->adjustment($dateFrom, $dateTo, $tid, 'debit');

            $cashMovement = ($cashIn + $cashLoaded + $creditAdjustments) - ($cashOut + $deposits + $debitAdjustments);
            $netBalance = $cashMovement + $runningBalance;

            $rows[] = [
                'kiosk' => $terminal->kiosk,
                'island' => $terminal->island,
                'location' => $terminal->location,
                'running_balance' => round($runningBalance, 2),
                'total_cash_in' => round($cashIn, 2),
                'total_cash_out' => round($cashOut, 2),
                'total_fee' => round($fees['total_fee'], 2),
                'total_vat' => round($fees['total_vat'], 2),
                'credit_adjustments' => round($creditAdjustments, 2),
                'debit_adjustments' => round($debitAdjustments, 2),
                'total_cash_loaded' => round($cashLoaded, 2),
                'total_deposits' => round($deposits, 2),
                'cash_movement' => round($cashMovement, 2),
                'net_balance' => round($netBalance, 2),
            ];
        }

        $totals = [
            'total_running_balance' => 0.0,
            'total_cash_in' => 0.0,
            'total_cash_out' => 0.0,
            'total_fee' => 0.0,
            'total_vat' => 0.0,
            'total_credit_adjustments' => 0.0,
            'total_debit_adjustments' => 0.0,
            'total_cash_loaded' => 0.0,
            'total_deposits' => 0.0,
        ];
        foreach ($rows as $row) {
            $totals['total_running_balance'] += $row['running_balance'];
            $totals['total_cash_in'] += $row['total_cash_in'];
            $totals['total_cash_out'] += $row['total_cash_out'];
            $totals['total_fee'] += $row['total_fee'];
            $totals['total_vat'] += $row['total_vat'];
            $totals['total_credit_adjustments'] += $row['credit_adjustments'];
            $totals['total_debit_adjustments'] += $row['debit_adjustments'];
            $totals['total_cash_loaded'] += $row['total_cash_loaded'];
            $totals['total_deposits'] += $row['total_deposits'];
        }
        $totalCreditTrx = $totals['total_cash_in'] + $totals['total_cash_loaded'] + $totals['total_credit_adjustments'];
        $totalDebitTrx = $totals['total_cash_out'] + $totals['total_deposits'] + $totals['total_debit_adjustments'];
        $totals['total_cash_movement'] = round($totalCreditTrx - $totalDebitTrx, 2);
        $totals['total_closing_net_balance'] = round($totals['total_cash_movement'] + $totals['total_running_balance'], 2);

        return ['rows' => $rows, 'totals' => $totals];
    }
}
