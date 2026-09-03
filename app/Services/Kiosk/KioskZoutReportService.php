<?php

namespace App\Services\Kiosk;

use App\Models\Mysuncash\KioskBranch;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Kiosk > Zout Reports" (legacy `administrator::kiosk_zout_reports()` /
 * `get_kiosk_zout_report()` / `sort_zout()` / `get_zout_details()`). A
 * read-only report over kiosk terminal "Zout" cash-count/reconciliation
 * settlements — one row per (settlement date, settlement number), joining
 * `ztrail` (the settlement header) to `ztrail_details` (one row per
 * denomination line item actually counted).
 *
 * The list query replicates legacy's exact join/GROUP BY shape so the row
 * count matches legacy precisely (verified: 21 rows unfiltered against the
 * live `dev_mysuncash` data as of 2026-09-03) — `ztrail_details.settlement_no`
 * is NULL on most rows (plain transaction-log entries, not settlements), so
 * the `INNER JOIN ... AND settlement_no IS NOT NULL` is load-bearing, not
 * decorative.
 *
 * Legacy also has a session-derived branch restriction for its own
 * "Kiosk Third Party User" (role=3) admin login — not replicated here,
 * matching the same decision already made for Kiosk Statement: this
 * rewrite's admin session is a different login system entirely (see
 * `KioskStatementController`), so branch scoping is just an explicit,
 * optional filter param instead of something auto-derived from the actor.
 *
 * Legacy's `location` filter dropdown offered exactly one hard-coded option
 * ("Bahamas" → literal value `bahamas`) that never matched any real
 * `ztrail.location` value (real values look like "FastPay" or "New
 * Providence,Bahamas") — an inert filter in legacy itself. Not replicated:
 * `listLocations()` instead returns the real distinct values so the
 * dropdown/filter actually works.
 *
 * `ztrail.settlement_date` is a `varchar(20)`, not a real DATE/DATETIME
 * column, and its values are inconsistently formatted across rows — some
 * `Y-m-d H:i:s` (a handful of older rows), most `m-d-Y h:i A` (e.g.
 * `02-13-2026 11:06 AM`, from PHP's own `date()` formatting on write).
 * MySQL's `DATE(...)` silently returns NULL for the latter format, so a
 * SQL-side `DATE(z.settlement_date) = ?` filter — the literal translation
 * of legacy's PHP-side date comparison — matched almost nothing. Filtering
 * is done in PHP instead (`parseSettlementDate()`, trying both known
 * formats) after fetching, which works regardless of which format a given
 * row was written in.
 *
 * Deliberately NOT replicated: legacy's export uses a SEPARATE query
 * (`get_export_zout_report()` / `export_sort_zout()`) grouped by
 * `(transaction_type, settlement_no)` instead of `(DATE(settlement_date),
 * settlement_no)` — every settlement touching more than one transaction
 * type explodes into multiple export rows (verified: 32 rows unfiltered,
 * vs. 21 list rows — legacy's own list/export totals disagree by design).
 * This port exports the same rows shown in the list instead, so the
 * on-screen count and the exported file's count always agree.
 */
class KioskZoutReportService
{
    private const SELECT = <<<'SQL'
        c.dba_name, kb.name AS kiosk_branch, kt.name AS kiosk_terminal,
        z.id, z.client_record_id, z.kiosk_branch_id, z.kiosk_terminal_id, z.location,
        z.settlement_date, z.settlement_no, MAX(z.timestamp) AS max_date,
        zd.terminal_reference, z.report_user, z.total_transactions,
        SUM(zd.denomination_usd1) AS denomination_usd1,
        SUM(zd.denomination_usd2) AS denomination_usd2,
        SUM(zd.denomination_usd5) AS denomination_usd5,
        SUM(zd.denomination_usd10) AS denomination_usd10,
        SUM(zd.denomination_usd20) AS denomination_usd20,
        SUM(zd.denomination_usd50) AS denomination_usd50,
        SUM(zd.denomination_usd100) AS denomination_usd100,
        SUM(zd.denomination_bsd1) AS denomination_bsd1,
        SUM(zd.denomination_bsd2) AS denomination_bsd2,
        SUM(zd.denomination_bsd5) AS denomination_bsd5,
        SUM(zd.denomination_bsd10) AS denomination_bsd10,
        SUM(zd.denomination_bsd20) AS denomination_bsd20,
        SUM(zd.denomination_bsd50) AS denomination_bsd50,
        SUM(zd.denomination_bsd100) AS denomination_bsd100
        SQL;

    private const FROM_JOIN = <<<'SQL'
        FROM ztrail z
        INNER JOIN ztrail_details zd ON (z.settlement_no = zd.settlement_no AND zd.settlement_no IS NOT NULL)
        INNER JOIN clients c ON (c.id = z.client_record_id)
        INNER JOIN kiosk_branch kb ON (kb.id = z.kiosk_branch_id)
        INNER JOIN kiosk_terminal kt ON (kt.id = zd.kiosk_terminal_id)
        SQL;

    public function listBranches(): array
    {
        return KioskBranch::where('status', KioskBranch::STATUS_ACTIVE)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->all();
    }

    public function listLocations(): array
    {
        return DB::connection('mysuncash')->table('ztrail')
            ->whereNotNull('location')
            ->where('location', '!=', '')
            ->distinct()
            ->orderBy('location')
            ->pluck('location')
            ->all();
    }

    /** `settlement_date` is a varchar with mixed formats — see class docblock. */
    private function parseSettlementDate(?string $value): ?Carbon
    {
        if (! $value) {
            return null;
        }

        foreach (['m-d-Y h:i A', 'Y-m-d H:i:s'] as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $value);
                if ($parsed !== false) {
                    return $parsed;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function present(object $row): array
    {
        return [
            'kiosk_id' => $row->terminal_reference,
            'location' => $row->location,
            'date' => $row->settlement_date,
            'settlement_no' => $row->settlement_no,
            'user' => $row->report_user,
            'previous_settlement' => $row->max_date,
            'total_transactions' => (int) $row->total_transactions,
        ];
    }

    public function list(?int $branchId = null, ?string $location = null, ?string $date = null): array
    {
        $where = [];
        $bindings = [];

        if ($branchId) {
            $where[] = 'kb.id = ?';
            $bindings[] = $branchId;
        }
        if (filled($location)) {
            $where[] = 'z.location = ?';
            $bindings[] = $location;
        }

        $whereSql = $where ? 'WHERE '.implode(' AND ', $where) : '';

        $sql = 'SELECT '.self::SELECT."\n".self::FROM_JOIN."\n{$whereSql}\nGROUP BY z.settlement_no";

        $rows = DB::connection('mysuncash')->select($sql, $bindings);

        if (filled($date)) {
            $rows = array_filter($rows, fn ($row) => $this->parseSettlementDate($row->settlement_date)?->toDateString() === $date);
        }

        return array_map(fn ($row) => $this->present($row), array_values($rows));
    }

    /**
     * @throws ValidationException
     */
    public function details(string $settlementNo): array
    {
        $sql = 'SELECT '.self::SELECT."\n".self::FROM_JOIN."\nWHERE z.settlement_no = ?\nGROUP BY z.settlement_no";

        $row = DB::connection('mysuncash')->selectOne($sql, [$settlementNo]);
        if (! $row) {
            throw ValidationException::withMessages(['settlement_no' => ['This settlement was not found.']]);
        }

        $usd = ['usd1' => 1, 'usd2' => 2, 'usd5' => 5, 'usd10' => 10, 'usd20' => 20, 'usd50' => 50, 'usd100' => 100];
        $bsd = ['bsd1' => 1, 'bsd2' => 2, 'bsd5' => 5, 'bsd10' => 10, 'bsd20' => 20, 'bsd50' => 50, 'bsd100' => 100];

        $denominations = [];
        $totalQtyUsd = 0;
        $totalAmountUsd = 0;
        foreach ($usd as $key => $faceValue) {
            $qty = (int) $row->{"denomination_{$key}"};
            $denominations[$key] = $qty;
            $totalQtyUsd += $qty;
            $totalAmountUsd += $qty * $faceValue;
        }
        $totalQtyBsd = 0;
        $totalAmountBsd = 0;
        foreach ($bsd as $key => $faceValue) {
            $qty = (int) $row->{"denomination_{$key}"};
            $denominations[$key] = $qty;
            $totalQtyBsd += $qty;
            $totalAmountBsd += $qty * $faceValue;
        }

        return array_merge($this->present($row), $denominations, [
            'total_qty_usd' => $totalQtyUsd,
            'total_amount_usd' => $totalAmountUsd,
            'total_qty_bsd' => $totalQtyBsd,
            'total_amount_bsd' => $totalAmountBsd,
        ]);
    }
}
