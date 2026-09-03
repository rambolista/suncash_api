<?php

namespace App\Services\Kiosk;

use App\Models\Mysuncash\Island;
use App\Models\Mysuncash\KioskTerminal;
use Illuminate\Support\Facades\DB;

/**
 * "Kiosk > Reports > Cash Exposure" tab (legacy `fastpay::cash_exposure_report()`
 * / `filter_cash_exposure_report()` / `Fastpay_kiosk_model::generate_cash_exposure_report()`).
 *
 * Unlike every other Kiosk report tab, this is a pure LIVE SNAPSHOT — legacy
 * accepts `$dateFrom`/`$dateTo` arguments but never actually uses them in the
 * query; it reads whatever balance is currently sitting on each active
 * terminal's `kiosk_terminal` row (`terminal_acceptor_balance`,
 * `terminal_dispenser_balance`, `cash_total_reserve`, `cash_total_reject`)
 * rather than aggregating transaction/meter history over a range. No date
 * filter is exposed in the port's UI either, matching legacy's own view
 * (its date inputs are commented out).
 *
 * Legacy also defines several unused meter-decoding helper methods
 * (`get_cash_exposure_acceptor/dispenser/reject()`,
 * `calculate_total_acceptor/reject()`) between the same comment markers as
 * this report — none of them are actually called by
 * `generate_cash_exposure_report()`, which reads the pre-computed balance
 * columns directly. Not ported; they're dead code even in legacy.
 *
 * "Cash Exposure" = Acceptor + Dispenser + Reserve + Reject (the legacy
 * per-row formula excludes the recycler leg; only the TOTALS row adds
 * recycler on top — replicated as two separate sums to match exactly).
 *
 * A row is flagged (legacy: pale-yellow highlight) when the acceptor
 * balance has reached its high-alert threshold (kiosk or atm terminals) OR
 * the dispenser balance has dropped to/below its low-alert threshold (atm
 * terminals only) — `terminal_type` is frequently NULL on this table, which
 * legacy's own `== 'kiosk' || == 'atm'` check naturally excludes from ever
 * flagging on the acceptor leg, replicated as-is.
 */
class KioskCashExposureReportService
{
    public function listTerminals(): array
    {
        return KioskTerminal::orderBy('name')->get(['id', 'name'])->all();
    }

    public function listIslands(): array
    {
        return Island::orderBy('name')->get(['id', 'code', 'name'])->all();
    }

    /** Legacy `get_kiosk_adjustment_transactions($today, $today, $terminalId, "deposit", "Recycled to Kiosk")` — cash physically recycled back into this terminal today. */
    private function cashRecycler(int $terminalId): float
    {
        $today = now()->toDateString();

        $total = DB::connection('mysuncash')->table('kiosk_terminal_transactions')
            ->where('terminal_id', $terminalId)
            ->where('trans_type', 'deposit')
            ->where('deposit_location', 'Recycled to Kiosk')
            ->whereBetween('create_date', ["{$today} 00:00:00", "{$today} 23:59:59"])
            ->sum('amount');

        return (float) $total;
    }

    public function list(?int $terminalId = null, ?int $islandId = null): array
    {
        $query = DB::connection('mysuncash')->table('kiosk_terminal as kt')
            ->leftJoin('island as i', 'i.id', '=', 'kt.island')
            ->where('kt.status', 'A');

        if ($terminalId) {
            $query->where('kt.id', $terminalId);
        }
        if ($islandId) {
            $query->where('i.id', $islandId);
        }

        $terminals = $query->get([
            'kt.id as terminal_id', 'kt.name as kiosk', 'i.name as island', 'kt.location',
            'kt.terminal_acceptor_balance', 'kt.terminal_dispenser_balance',
            'kt.acceptor_high_alert', 'kt.dispenser_low_alert',
            'kt.cash_total_reserve', 'kt.cash_total_reject', 'kt.terminal_type',
        ]);

        $rows = [];
        $totalRecycler = 0.0;
        foreach ($terminals as $terminal) {
            $acceptor = (float) $terminal->terminal_acceptor_balance;
            $dispenser = (float) $terminal->terminal_dispenser_balance;
            $reserve = (float) $terminal->cash_total_reserve;
            $reject = (float) $terminal->cash_total_reject;
            $recycler = $this->cashRecycler((int) $terminal->terminal_id);
            $totalRecycler += $recycler;

            $flagged = ($acceptor >= (float) $terminal->acceptor_high_alert && in_array($terminal->terminal_type, ['kiosk', 'atm'], true))
                || ($dispenser <= (float) $terminal->dispenser_low_alert && $terminal->terminal_type === 'atm');

            $rows[] = [
                'kiosk' => $terminal->kiosk,
                'island' => $terminal->island,
                'location' => $terminal->location,
                'cash_acceptor' => round($acceptor, 2),
                'cash_dispenser' => round($dispenser, 2),
                'cash_reserve' => round($reserve, 2),
                'cash_reject' => round($reject, 2),
                'cash_exposure' => round($acceptor + $dispenser + $reserve + $reject, 2),
                'flagged' => $flagged,
            ];
        }

        $totals = [
            'total_acceptor' => round(array_sum(array_column($rows, 'cash_acceptor')), 2),
            'total_dispenser' => round(array_sum(array_column($rows, 'cash_dispenser')), 2),
            'total_reserve' => round(array_sum(array_column($rows, 'cash_reserve')), 2),
            'total_reject' => round(array_sum(array_column($rows, 'cash_reject')), 2),
            'total_recycler' => round($totalRecycler, 2),
        ];
        $totals['total_exposure'] = round(
            $totals['total_acceptor'] + $totals['total_dispenser'] + $totals['total_reserve'] + $totals['total_reject'] + $totals['total_recycler'],
            2
        );

        return ['rows' => $rows, 'totals' => $totals];
    }
}
