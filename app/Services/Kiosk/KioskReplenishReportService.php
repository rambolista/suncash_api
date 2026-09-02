<?php

namespace App\Services\Kiosk;

use App\Models\Mysuncash\KioskBranch;
use App\Models\Mysuncash\KioskMeterUser;
use App\Models\Mysuncash\KioskTerminal;
use Illuminate\Support\Facades\DB;

/**
 * "Kiosk > Replenish Reports" (legacy `fastpay::replenish()` /
 * `search_rterminal()` / `view_rmeter()` / `view_rcash()` /
 * `view_racceptor()` — the controller lives in `fastpay.php`, like Cash
 * Meters and Monitoring Dashboard, not `administrator.php`). One row per
 * kiosk terminal that has ANY `kiosk_meters_user` event, with three
 * per-terminal drill-down views:
 *
 * - **View Meter**: the terminal's latest REPLENISH event, aggregated to
 *   one row per denomination (Denom / Count / Total Value) + a grand total.
 * - **View Add Cash**: the SAME REPLENISH event, broken out per physical
 *   cassette bin instead (Bin / Denom / Count / Value) — legacy shows no
 *   grand total here, only View Meter and View Clear Acceptor get one.
 * - **View Clear Acceptor**: the terminal's latest PRINT_CLEAR_ACCEPTOR_METERS
 *   event, decoded the same way Cash Meters' Acceptor view decodes
 *   `BILL_ACCEPTOR_CURRENT_*` keys (Denom / Count / Value) + a grand total.
 *   Legacy also fetches a PRINT_CLEAR_DISPENSER_METERS row for this same
 *   button, but a hard-coded `$type` variable makes that branch dead code
 *   (unreachable in production) — not replicated, since it never runs.
 *
 * The list query replicates legacy's literal `GROUP BY km.terminal_id`
 * with no deterministic per-terminal row order (legacy has none either —
 * `SELECT km.*` grouped with no aggregate/ORDER BY on `km.id`), so which
 * category/date is shown per terminal in the list is MySQL's arbitrary
 * pick, exactly as in legacy — verified to return the same 21 rows as
 * legacy against live data. The three View endpoints do not depend on
 * that pick: each queries its own specific category directly.
 */
class KioskReplenishReportService
{
    private const REPLENISH = 'REPLENISH';

    private const CLEAR_ACCEPTOR = 'PRINT_CLEAR_ACCEPTOR_METERS';

    public function listBranches(): array
    {
        return KioskBranch::where('status', KioskBranch::STATUS_ACTIVE)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->all();
    }

    /** Branch→Terminal cascade — legacy applies no status filter here (terminals of any status in that branch). */
    public function listTerminalsForBranch(int $branchId): array
    {
        return KioskTerminal::where('kiosk_branch_id', $branchId)
            ->orderBy('name')
            ->get(['id', 'name', 'kiosk_branch_id', 'status'])
            ->all();
    }

    /**
     * The main list — one arbitrary `kiosk_meters_user` row per terminal
     * that has any, replicating legacy's exact join/GROUP BY.
     */
    public function list(?int $terminalId = null, ?int $branchId = null): array
    {
        $where = [];
        $bindings = [];

        if ($terminalId) {
            $where[] = 'km.terminal_id = ?';
            $bindings[] = $terminalId;
        }
        if ($branchId) {
            $where[] = 'kt.kiosk_branch_id = ?';
            $bindings[] = $branchId;
        }

        $whereSql = $where ? 'WHERE '.implode(' AND ', $where) : '';

        $sql = <<<SQL
            SELECT km.*, kt.name AS kiosk_name, kt.id AS kiosk_terminal_id, kt.code AS kiosk_code, i.name AS island, kt.location
            FROM kiosk_meters_user km
            INNER JOIN kiosk_terminal kt ON kt.id = km.terminal_id
            LEFT JOIN island i ON i.id = kt.island
            {$whereSql}
            GROUP BY km.terminal_id
            ORDER BY kt.name ASC
            SQL;

        $rows = DB::connection('mysuncash')->select($sql, $bindings);

        return array_map(fn ($row) => [
            'terminal_id' => (int) $row->terminal_id,
            'replenishment_date' => $row->timestamp,
            'kiosk_terminal' => $row->kiosk_name,
            'island' => $row->island,
            'location' => $row->location,
        ], $rows);
    }

    private function emptyBucket(string $denom): array
    {
        return ['denom' => $denom, 'count' => 0, 'value' => 0];
    }

    private function isFlatMap(mixed $decoded): bool
    {
        return is_array($decoded) && $decoded !== [] && ! array_is_list($decoded);
    }

    private function latestRow(int $terminalId, string $category): ?KioskMeterUser
    {
        return KioskMeterUser::where('terminal_id', $terminalId)
            ->where('category', $category)
            ->orderBy('id')
            ->first();
    }

    /**
     * View Meter — REPLENISH event aggregated to one row per denomination
     * from `dispense_cassette` (Identifier→Denomination) matched against
     * `BILL_DISPENSER_CURRENT_{Identifier}` keys in `data`.
     */
    public function meterDetail(int $terminalId): array
    {
        $row = $this->latestRow($terminalId, self::REPLENISH);
        if (! $row) {
            return ['date' => null, 'rows' => [], 'totals' => null];
        }

        $data = json_decode((string) $row->data, true);
        $cassettes = json_decode((string) $row->dispense_cassette, true);
        if (! $this->isFlatMap($data) || ! is_array($cassettes) || $cassettes === []) {
            return ['date' => $row->timestamp, 'rows' => [], 'totals' => null];
        }

        $buckets = [];
        foreach ($cassettes as $cassette) {
            $identifier = $cassette['Identifier'] ?? null;
            $denom = (string) ($cassette['Denomination'] ?? '');
            if ($identifier === null || $denom === '') {
                continue;
            }
            $count = 0;
            foreach ($data as $key => $value) {
                if (str_contains($key, "BILL_DISPENSER_CURRENT_{$identifier}")) {
                    $count += (int) $value;
                }
            }
            $buckets[$denom] = ['denom' => $denom, 'count' => $count, 'value' => (int) $denom * $count];
        }

        uksort($buckets, fn ($a, $b) => (int) $a <=> (int) $b);
        $rows = array_values($buckets);

        $totals = $this->emptyBucket('Total');
        foreach ($rows as $r) {
            $totals['count'] += $r['count'];
            $totals['value'] += $r['value'];
        }

        return ['date' => $row->timestamp, 'rows' => $rows, 'totals' => $totals];
    }

    /**
     * View Add Cash — the SAME REPLENISH event as View Meter, but one row
     * per physical cassette bin (Identifier) instead of aggregated by
     * denomination. No grand total, matching legacy.
     */
    public function addCashDetail(int $terminalId): array
    {
        $row = $this->latestRow($terminalId, self::REPLENISH);
        if (! $row) {
            return ['date' => null, 'rows' => []];
        }

        $data = json_decode((string) $row->data, true);
        $cassettes = json_decode((string) $row->dispense_cassette, true);
        if (! $this->isFlatMap($data) || ! is_array($cassettes) || $cassettes === []) {
            return ['date' => $row->timestamp, 'rows' => []];
        }

        $rows = [];
        foreach ($cassettes as $cassette) {
            $identifier = $cassette['Identifier'] ?? null;
            $denom = (string) ($cassette['Denomination'] ?? '');
            if ($identifier === null || $denom === '') {
                continue;
            }
            $count = 0;
            foreach ($data as $key => $value) {
                if (str_contains($key, "BILL_DISPENSER_CURRENT_{$identifier}")) {
                    $count += (int) $value;
                }
            }
            $rows[] = ['bin' => $identifier, 'denom' => $denom, 'count' => $count, 'value' => (int) $denom * $count];
        }

        usort($rows, fn ($a, $b) => $a['bin'] <=> $b['bin']);

        return ['date' => $row->timestamp, 'rows' => $rows];
    }

    /**
     * View Clear Acceptor — PRINT_CLEAR_ACCEPTOR_METERS event, decoding
     * `BILL_ACCEPTOR_CURRENT_*` keys exactly like Cash Meters' Acceptor
     * parser (`KioskCashMeterService::parseAcceptor()`), but reporting only
     * Denom/Count/Value per legacy's narrower "Clear Acceptor" table.
     */
    public function clearAcceptorDetail(int $terminalId): array
    {
        $row = $this->latestRow($terminalId, self::CLEAR_ACCEPTOR);
        if (! $row) {
            return ['rows' => [], 'totals' => null];
        }

        $data = json_decode((string) $row->data, true);
        if (! $this->isFlatMap($data)) {
            return ['rows' => [], 'totals' => null];
        }

        $buckets = [];
        foreach ($data as $key => $value) {
            if (preg_match('/^BILL_ACCEPTOR_CURRENT_(100|\d{1,2})$/', $key, $m)) {
                $denom = $m[1];
                $buckets[$denom] ??= $this->emptyBucket($denom);
                $buckets[$denom]['count'] += (int) $value;
                $buckets[$denom]['value'] = (int) $denom * $buckets[$denom]['count'];
            }
        }

        ksort($buckets, SORT_NUMERIC);
        $rows = array_values($buckets);

        $totals = $this->emptyBucket('Total');
        foreach ($rows as $r) {
            $totals['count'] += $r['count'];
            $totals['value'] += $r['value'];
        }

        return ['rows' => $rows, 'totals' => $totals];
    }
}
