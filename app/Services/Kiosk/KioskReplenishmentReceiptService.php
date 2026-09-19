<?php

namespace App\Services\Kiosk;

use App\Models\Mysuncash\KioskBranch;
use App\Models\Mysuncash\KioskTerminal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Kiosk > Reprint Replenishment Receipt" (legacy `fastpay::
 * replenishment_receipt()` / `search_replenishment_settlement()` /
 * `get_replenishment_by_settlement_no()` / `kiosk_model::
 * get_replenishment_settlements()` / `get_settlement_info()`). Search
 * kiosk cash-management/maintenance settlements — bill acceptor/dispenser
 * meter clears, full Z-out replenishments, cashbox clears, cash loads, and
 * reserve management — then reprint the settlement's receipt.
 *
 * This is an entirely operator-facing tool: a "replenishment" here is a
 * kiosk maintenance event (an admin/field-tech clearing meters or loading
 * cash into the machine), not a customer transaction — there is no
 * customer identity anywhere in this data. Legacy has no send/SMS action
 * for this feature at all, only a local browser print; no reprint-audit
 * log either (unlike Reprint Receipt, which does log every reprint) —
 * both are replicated as-is.
 *
 * Two distinct data sources, matching legacy exactly:
 *  - Bill acceptor/dispenser meter clears read `ztrail`/`ztrail_details`
 *    (just to enumerate real settlements) plus `kiosk_meters_user` (the
 *    actual cash total + flat-map denomination JSON — the same decode
 *    already used by KioskCashMeterService/KioskConfirmCustomerService).
 *  - Every other type (full replenishment, cashbox, add cash, reserve
 *    management) reads `kiosk_maintenance_details`, whose `meter` column
 *    uses a different, list-shaped JSON
 *    (`[{"Denom":1,"Currency":"BSD","Count":100,"Type":1}, ...]`,
 *    `Type`: 0=Reserve, 1=Recycler, 2=Cashbox, 3=Reject) — legacy's
 *    `extract_meter_denom_v2_with_currency()`, ported here.
 */
class KioskReplenishmentReceiptService
{
    public const METER_TYPES = [
        'clear_acceptor_meter' => 'Bill Acceptor Meters',
        'clear_dispenser_meter' => 'Bill Dispenser Meters',
        'full_replenishment' => 'Full Replenishment',
        'clear_cashbox' => 'Clear Cashbox',
        'add_cash' => 'Add Cash (Load Recycler)',
        'manage_load_reserve' => 'Manage Reserve - Load',
        'manage_add_reserve' => 'Manage Reserve - Add',
        'manage_clear_reserve' => 'Manage Reserve - Clear',
        'manage_set_reserve' => 'Manage Reserve - Set',
    ];

    private const DENOM_TYPE_LABELS = [0 => 'Reserve', 1 => 'Recycler', 2 => 'Cashbox', 3 => 'Reject'];

    private function db(): \Illuminate\Database\Connection
    {
        return DB::connection('mysuncash');
    }

    public function listTerminals(): array
    {
        return KioskTerminal::where('status', KioskTerminal::STATUS_ACTIVE)
            ->orderBy('name')
            ->get(['id', 'name', 'location'])
            ->all();
    }

    public function listBranches(): array
    {
        return KioskBranch::where('status', KioskBranch::STATUS_ACTIVE)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->all();
    }

    /**
     * @throws ValidationException
     */
    private function assertValidMeterType(string $meterType): void
    {
        if (! array_key_exists($meterType, self::METER_TYPES)) {
            throw ValidationException::withMessages(['meter_type' => ['Select a valid replenishment type.']]);
        }
    }

    /**
     * @throws ValidationException
     */
    public function search(string $meterType, string $dateFrom, string $dateTo, ?int $terminalId, ?int $branchId): array
    {
        $this->assertValidMeterType($meterType);

        if (in_array($meterType, ['clear_acceptor_meter', 'clear_dispenser_meter'], true)) {
            return $this->searchMeterClear($meterType, $dateFrom, $dateTo, $terminalId, $branchId);
        }
        if ($meterType === 'full_replenishment') {
            return $this->searchMaintenance($dateFrom, $dateTo, $terminalId, $branchId, 'full replenishment', null, 'Full Replenishment');
        }
        if (str_starts_with($meterType, 'manage_')) {
            $action = substr($meterType, strlen('manage_'));

            return $this->searchMaintenance($dateFrom, $dateTo, $terminalId, $branchId, 'manage reserve', $action, 'Manage Reserve ('.str_replace('_', ' ', $action).')');
        }

        $type = $meterType === 'clear_cashbox' ? 'clear cashbox' : 'add cash';
        $action = $meterType === 'clear_cashbox' ? 'clear_cashbox' : 'load_recycler';
        $label = $meterType === 'clear_cashbox' ? 'Clear Cashbox' : 'Add Cash - Load Recycler';

        return $this->searchMaintenance($dateFrom, $dateTo, $terminalId, $branchId, $type, $action, $label);
    }

    private function searchMeterClear(string $meterType, string $dateFrom, string $dateTo, ?int $terminalId, ?int $branchId): array
    {
        $rows = $this->db()->table('ztrail as z')
            ->join('kiosk_terminal as kt', 'kt.id', '=', 'z.kiosk_terminal_id')
            ->join('ztrail_details as zd', 'z.settlement_no', '=', 'zd.settlement_no')
            ->leftJoin('island as i', 'i.id', '=', 'kt.island')
            ->join('kiosk_branch as kb', 'kb.id', '=', 'kt.kiosk_branch_id')
            ->whereNotNull('z.settlement_no')
            ->whereDate('z.timestamp', '>=', $dateFrom)
            ->whereDate('z.timestamp', '<=', $dateTo)
            ->when($terminalId, fn ($q) => $q->where('z.kiosk_terminal_id', $terminalId))
            ->when($branchId, fn ($q) => $q->where('kb.id', $branchId))
            ->groupBy('z.settlement_no')
            ->orderByDesc('z.id')
            ->selectRaw("kt.name, kt.location, i.name AS island, z.kiosk_branch_id, z.kiosk_terminal_id,
                z.settlement_no, z.settlement_date, z.report_user, kb.name AS branch, z.timestamp")
            ->get();

        return $rows->map(function ($row) use ($meterType) {
            $total = $this->meterClearTotal($meterType, (int) $row->kiosk_terminal_id, (string) $row->timestamp);

            return [
                'name' => $row->name,
                'location' => $row->location,
                'island' => $row->island,
                'branch' => $row->branch,
                'kiosk_terminal_id' => (int) $row->kiosk_terminal_id,
                'settlement_no' => $row->settlement_no,
                'settlement_date' => $row->settlement_date,
                'report_user' => $row->report_user,
                'transaction_type' => $meterType === 'clear_acceptor_meter' ? 'Bill Acceptor' : 'Bill Dispenser',
                'total_transactions' => $total,
            ];
        })->all();
    }

    private function searchMaintenance(string $dateFrom, string $dateTo, ?int $terminalId, ?int $branchId, string $type, ?string $action, string $label): array
    {
        $groupBySettlement = $action === null; // matches legacy: only full_replenishment groups by settlement_no

        $query = $this->db()->table('kiosk_maintenance_details as kmd')
            ->join('ztrail as z', 'z.settlement_no', '=', 'kmd.settlement_no')
            ->join('kiosk_terminal as kt', 'kt.id', '=', 'kmd.terminal_id')
            ->join('kiosk_branch as kb', 'kb.id', '=', 'kt.kiosk_branch_id')
            ->leftJoin('island as i', 'i.id', '=', 'kt.island')
            ->where('kmd.type', $type)
            ->when($action, fn ($q) => $q->where('kmd.action', $action))
            ->whereDate('kmd.created_date', '>=', $dateFrom)
            ->whereDate('kmd.created_date', '<=', $dateTo)
            ->when($terminalId, fn ($q) => $q->where('kmd.terminal_id', $terminalId))
            ->when($branchId, fn ($q) => $q->where('kb.id', $branchId))
            ->orderByDesc('kmd.id')
            ->selectRaw("kmd.terminal_id AS kiosk_terminal_id, kmd.settlement_no, kmd.id AS ref_id,
                kt.name, kt.location, i.name AS island, kmd.created_by AS report_user, kmd.created_date AS settlement_date,
                kb.name AS branch, '{$label}' AS transaction_type");

        if ($groupBySettlement) {
            $query->groupBy('kmd.settlement_no');
        }

        return $query->get()->map(fn ($row) => [
            'name' => $row->name,
            'location' => $row->location,
            'island' => $row->island,
            'branch' => $row->branch,
            'kiosk_terminal_id' => (int) $row->kiosk_terminal_id,
            'settlement_no' => $row->settlement_no,
            'settlement_date' => $row->settlement_date,
            'report_user' => $row->report_user,
            'transaction_type' => $row->transaction_type,
            'ref_id' => (int) $row->ref_id,
        ])->all();
    }

    /**
     * @throws ValidationException
     */
    public function detail(string $meterType, string $settlementNo, ?int $refId): array
    {
        $this->assertValidMeterType($meterType);

        $result = match (true) {
            in_array($meterType, ['clear_acceptor_meter', 'clear_dispenser_meter'], true) => $this->meterClearDetail($meterType, $settlementNo),
            $meterType === 'clear_cashbox' => $this->maintenanceDetail($settlementNo, $refId, 'clear cashbox', 'Cashbox', 'cashbox', 'LOAD'),
            $meterType === 'add_cash' => $this->maintenanceDetail($settlementNo, $refId, 'add cash', 'Recycler', 'add_cash', 'Add Cash'),
            $meterType === 'full_replenishment' => $this->fullReplenishmentDetail($settlementNo),
            str_starts_with($meterType, 'manage_') => $this->manageReserveDetail($meterType, $settlementNo, $refId),
            default => null,
        };

        if (! $result) {
            throw ValidationException::withMessages(['settlement_no' => ['Unable to retrieve replenishment data.']]);
        }

        return $result;
    }

    // ── clear_acceptor_meter / clear_dispenser_meter ────────────────────────

    private function meterClearDetail(string $meterType, string $settlementNo): ?array
    {
        $row = $this->db()->table('ztrail as z')
            ->join('kiosk_terminal as kt', 'kt.id', '=', 'z.kiosk_terminal_id')
            ->where('z.settlement_no', $settlementNo)
            ->orderByDesc('z.id')
            ->selectRaw('z.kiosk_terminal_id, z.settlement_no, z.timestamp AS settlement_date, kt.name, kt.location, z.report_user')
            ->first();

        if (! $row) {
            return null;
        }

        $terminalId = (int) $row->kiosk_terminal_id;
        $meter = $this->meterClearDenominations($meterType, $terminalId, (string) $row->settlement_date);
        $previous = $this->previousMeterClearSettlement($terminalId, (string) $row->settlement_date, $settlementNo);

        return array_merge([
            'name' => $row->name,
            'location' => $row->location,
            'settlement_date' => $row->settlement_date,
            'report_user' => $row->report_user,
            'settlement_no' => $row->settlement_no,
            'kiosk_terminal_id' => $terminalId,
            'transaction_type' => $meterType === 'clear_acceptor_meter' ? 'Bill Acceptor' : 'Bill Dispenser',
            'previous_settlement_no' => $previous['settlement_no'] ?? null,
            'previous_settlement_date' => $previous['settlement_date'] ?? null,
        ], $meter);
    }

    private function previousMeterClearSettlement(int $terminalId, string $date, string $settlementNo): array
    {
        $row = $this->db()->table('ztrail')
            ->where('kiosk_terminal_id', $terminalId)
            ->where('settlement_no', '!=', $settlementNo)
            ->whereDate('timestamp', '<=', $date)
            ->orderByDesc('id')
            ->select('settlement_no', 'settlement_date')
            ->first();

        return $row ? (array) $row : [];
    }

    /** Total cash + per-denomination breakdown for a meter-clear settlement, from `kiosk_meters_user`. */
    private function meterClearDenominations(string $meterType, int $terminalId, string $date): array
    {
        $category = $meterType === 'clear_acceptor_meter' ? 'PRINT_CLEAR_ACCEPTOR_METERS' : 'PRINT_CLEAR_DISPENSER_METERS';

        $row = $this->db()->table('kiosk_meters_user as kmu')
            ->whereDate('kmu.timestamp', $date)
            ->where('kmu.category', $category)
            ->where('kmu.terminal_id', $terminalId)
            ->orderBy('kmu.id')
            ->select('total_meter', 'data', 'dispense_cassette')
            ->first();

        $denomKeys = [1, 2, 5, 10, 20, 50, 100];
        $zeroed = array_combine(array_map(fn ($d) => "USD_{$d}", $denomKeys), array_fill(0, count($denomKeys), 0));

        if (! $row) {
            return array_merge($zeroed, ['total_cash' => '0.00']);
        }

        $counts = $meterType === 'clear_acceptor_meter'
            ? $this->extractAcceptorDenomination((string) $row->data)
            : $this->extractDispenserDenomination((string) $row->data, $row->dispense_cassette);

        $usd = $zeroed;
        foreach ($counts as $denom => $count) {
            $usd["USD_{$denom}"] = $count;
        }

        return array_merge($usd, ['total_cash' => (string) $row->total_meter]);
    }

    /** Legacy `kiosk_meter_model::extract_acceptor_denomination()` — flat-map `BILL_ACCEPTOR_CURRENT_N` keys. */
    private function extractAcceptorDenomination(string $json): array
    {
        $decoded = json_decode($json, true);
        if (! is_array($decoded) || array_is_list($decoded)) {
            return [];
        }

        $counts = [];
        foreach ($decoded as $key => $value) {
            if (preg_match('/^BILL_ACCEPTOR_CURRENT_(100|\d{1,2})$/', (string) $key, $m) && $value > 0) {
                $counts[(int) $m[1]] = ($counts[(int) $m[1]] ?? 0) + $value;
            }
        }

        return $counts;
    }

    /** Legacy `kiosk_meter_model::extract_dispenser_denomination()` — cassette `Identifier` -> `Denomination` map. */
    private function extractDispenserDenomination(string $json, ?string $cassetteJson): array
    {
        $decoded = json_decode($json, true);
        $cassettes = json_decode((string) $cassetteJson, true);
        if (! is_array($decoded) || array_is_list($decoded) || ! is_array($cassettes) || $cassettes === []) {
            return [];
        }

        $counts = [];
        foreach ($decoded as $key => $value) {
            if ($value <= 0) {
                continue;
            }
            foreach ($cassettes as $cassette) {
                $identifier = $cassette['Identifier'] ?? null;
                $denom = $cassette['Denomination'] ?? null;
                if ($identifier === null || $denom === null) {
                    continue;
                }
                if (str_contains((string) $key, "BILL_DISPENSER_CURRENT_{$identifier}")) {
                    $counts[(int) $denom] = ($counts[(int) $denom] ?? 0) + $value;
                }
            }
        }

        return $counts;
    }

    // ── clear_cashbox / add_cash ─────────────────────────────────────────────

    private function maintenanceDetail(string $settlementNo, ?int $refId, string $type, string $bucket, string $legacyMeterType, string $transactionType): ?array
    {
        $row = $this->db()->table('kiosk_maintenance_details as kmd')
            ->join('kiosk_terminal as kt', 'kt.id', '=', 'kmd.terminal_id')
            ->where('kmd.type', $type)
            ->where('kmd.settlement_no', $settlementNo)
            ->when($refId, fn ($q) => $q->where('kmd.id', $refId))
            ->selectRaw('kmd.terminal_id AS kiosk_terminal_id, kmd.settlement_no, kt.name, kt.location, kmd.created_by AS report_user, kmd.created_date AS settlement_date, kmd.meter AS denominations, kmd.action')
            ->first();

        if (! $row) {
            return null;
        }

        $denominations = $this->extractMeterDenomWithCurrency((string) $row->denominations);
        $bucketData = $denominations[$bucket] ?? [];
        if (empty($bucketData)) {
            return null;
        }

        $previous = $this->previousMaintenanceSettlement((int) $row->kiosk_terminal_id, (string) $row->settlement_date, $legacyMeterType, (int) ($refId ?? 0));

        return [
            'name' => $row->name,
            'location' => $row->location,
            $bucket => $bucketData,
            'report_user' => $row->report_user,
            'settlement_no' => $row->settlement_no,
            'settlement_date' => $row->settlement_date,
            'kiosk_terminal_id' => (int) $row->kiosk_terminal_id,
            'transaction_type' => $transactionType,
            'previous_settlement_no' => $previous['settlement_no'] ?? null,
            'previous_settlement_date' => $previous['settlement_date'] ?? null,
        ];
    }

    // ── full_replenishment ───────────────────────────────────────────────────

    private function fullReplenishmentDetail(string $settlementNo): ?array
    {
        $rows = $this->db()->table('kiosk_maintenance_details as kmd')
            ->join('kiosk_terminal as kt', 'kt.id', '=', 'kmd.terminal_id')
            ->where('kmd.type', 'full replenishment')
            ->where('kmd.settlement_no', $settlementNo)
            ->orderByDesc('kmd.id')
            ->selectRaw('kmd.terminal_id AS kiosk_terminal_id, kmd.settlement_no, kt.name, kt.location, kmd.created_by AS report_user, kmd.created_date AS settlement_date, kmd.meter AS denominations, kmd.action')
            ->get();

        if ($rows->isEmpty()) {
            return null;
        }

        $first = $rows->first();
        $previous = $this->previousMaintenanceSettlement((int) $first->kiosk_terminal_id, (string) $first->settlement_date, 'full_replenishment', 0);

        $buckets = ['ClearRecycler' => null, 'ClearCashbox' => null, 'ClearReserve' => null, 'LoadReserve' => null];
        foreach ($rows as $row) {
            $decoded = $this->extractMeterDenomWithCurrency((string) $row->denominations);
            $target = match ($row->action) {
                'clear_recycler' => 'ClearRecycler',
                'clear_cashbox' => 'ClearCashbox',
                'clear_reserve' => 'ClearReserve',
                'load_reserve' => 'LoadReserve',
                default => null,
            };
            if ($target === null || $buckets[$target] !== null) {
                continue;
            }
            $bucketKey = match ($row->action) {
                'clear_recycler' => 'Recycler',
                'clear_cashbox' => 'Cashbox',
                default => 'Reserve',
            };
            $buckets[$target] = $decoded[$bucketKey] ?? [];
        }

        return [
            'name' => $first->name,
            'location' => $first->location,
            'report_user' => $first->report_user,
            'settlement_no' => $first->settlement_no,
            'settlement_date' => $first->settlement_date,
            'transaction_type' => 'Full Replenishment',
            'ClearCashbox' => $buckets['ClearCashbox'] ?? [],
            'ClearRecycler' => $buckets['ClearRecycler'] ?? [],
            'ClearReserve' => $buckets['ClearReserve'] ?? [],
            'LoadReserve' => $buckets['LoadReserve'] ?? [],
            'kiosk_terminal_id' => (int) $first->kiosk_terminal_id,
            'previous_settlement_no' => $previous['settlement_no'] ?? null,
            'previous_settlement_date' => $previous['settlement_date'] ?? null,
        ];
    }

    // ── manage_load_reserve / manage_add_reserve / manage_clear_reserve / manage_set_reserve ──

    private function manageReserveDetail(string $meterType, string $settlementNo, ?int $refId): ?array
    {
        $action = substr($meterType, strlen('manage_'));

        $row = $this->db()->table('kiosk_maintenance_details as kmd')
            ->join('kiosk_terminal as kt', 'kt.id', '=', 'kmd.terminal_id')
            ->where('kmd.type', 'manage reserve')
            ->where('kmd.action', $action)
            ->where('kmd.settlement_no', $settlementNo)
            ->when($refId, fn ($q) => $q->where('kmd.id', $refId))
            ->orderByDesc('kmd.id')
            ->selectRaw('kmd.terminal_id AS kiosk_terminal_id, kmd.settlement_no, kt.name, kt.location, kmd.created_by AS report_user, kmd.created_date AS settlement_date, kmd.meter AS denominations, kmd.action')
            ->first();

        if (! $row) {
            return null;
        }

        $decoded = $this->extractMeterDenomWithCurrency((string) $row->denominations);
        $previousRow = $this->previousMaintenanceRow((int) $row->kiosk_terminal_id, (string) $row->settlement_date, $meterType, (int) ($refId ?? 0));
        $previousDecoded = $previousRow ? $this->extractMeterDenomWithCurrency((string) $previousRow['denominations']) : null;

        return [
            'name' => $row->name,
            'location' => $row->location,
            'report_user' => $row->report_user,
            'settlement_no' => $row->settlement_no,
            'settlement_date' => $row->settlement_date,
            'transaction_type' => 'Manage Reserve - '.str_replace('_', ' ', $action),
            'LoadReserve' => $decoded['Reserve'] ?? [],
            'PreviousReserve' => $previousDecoded['Reserve'] ?? [],
            'kiosk_terminal_id' => (int) $row->kiosk_terminal_id,
            'previous_settlement_no' => $previousRow['settlement_no'] ?? null,
            'previous_settlement_date' => $previousRow['settlement_date'] ?? null,
        ];
    }

    private function previousMaintenanceSettlement(int $terminalId, string $date, string $meterType, int $refId): array
    {
        $row = $this->previousMaintenanceRow($terminalId, $date, $meterType, $refId);

        return $row ? ['settlement_no' => $row['settlement_no'], 'settlement_date' => $row['settlement_date']] : [];
    }

    /** Legacy `get_previous_settlement()` for the `kiosk_maintenance_details`-backed types. */
    private function previousMaintenanceRow(int $terminalId, string $date, string $meterType, int $refId): ?array
    {
        $query = $this->db()->table('kiosk_maintenance_details')
            ->where('terminal_id', $terminalId)
            ->where('created_date', '<=', $date)
            ->where('id', '!=', $refId)
            ->orderByDesc('id');

        if ($meterType === 'clear_cashbox') {
            $query->where('action', 'clear_cashbox')->where('type', 'clear cashbox');
        } elseif (str_contains($meterType, 'reserve')) {
            $query->where('action', 'like', '%reserve%');
        } elseif ($meterType === 'add_cash') {
            $query->where('action', 'load_recycler');
        }

        $row = $query->select('id as ref_id', 'settlement_no', 'meter as denominations', 'created_date as settlement_date', 'action')->first();

        return $row ? (array) $row : null;
    }

    /**
     * Legacy `kiosk_meter_model::extract_meter_denom_v2_with_currency()` —
     * decodes the list-shaped `[{"Denom":N,"Currency":"BSD","Count":N,
     * "Type":T}, ...]` JSON into per-type (Reserve/Recycler/Cashbox/Reject)
     * buckets, each `{<currency>: {<denom>: {count, total_value}},
     * total_<currency>, total_cash}`.
     */
    private function extractMeterDenomWithCurrency(string $json): array
    {
        $decoded = json_decode($json, true);
        $result = ['Cashbox' => [], 'Reject' => [], 'Recycler' => [], 'Reserve' => []];
        if (! is_array($decoded)) {
            return $result;
        }

        $totals = ['Cashbox' => [], 'Reject' => [], 'Recycler' => [], 'Reserve' => []];
        foreach ($decoded as $row) {
            $typeName = self::DENOM_TYPE_LABELS[$row['Type'] ?? null] ?? 'Unknown';
            if (! array_key_exists($typeName, $result)) {
                continue;
            }
            $currency = $row['Currency'] ?: 'UNKNOWN';
            $denom = (int) ($row['Denom'] ?? 0);
            $count = (int) ($row['Count'] ?? 0);

            $result[$typeName][$currency][$denom] ??= ['count' => 0, 'total_value' => 0];
            $result[$typeName][$currency][$denom]['count'] += $count;
            $result[$typeName][$currency][$denom]['total_value'] += $denom * $count;

            $totals[$typeName][$currency] = ($totals[$typeName][$currency] ?? 0) + ($denom * $count);
        }

        foreach ($totals as $type => $currencies) {
            $sum = 0;
            foreach ($currencies as $currency => $value) {
                $result[$type]["total_{$currency}"] = number_format($value, 2, '.', '');
                $sum += $value;
            }
            $result[$type]['total_cash'] = number_format($sum, 2, '.', '');
        }

        return $result;
    }

    /** Total cash for a meter-clear settlement (list view) — legacy's `get_receipt_clear_meter(..., is_total_meter: true)`. */
    private function meterClearTotal(string $meterType, int $terminalId, string $timestamp): string
    {
        $category = $meterType === 'clear_acceptor_meter' ? 'PRINT_CLEAR_ACCEPTOR_METERS' : 'PRINT_CLEAR_DISPENSER_METERS';

        $total = $this->db()->table('kiosk_meters_user')
            ->whereDate('timestamp', $timestamp)
            ->where('category', $category)
            ->where('terminal_id', $terminalId)
            ->orderBy('id')
            ->value('total_meter');

        return $total !== null ? (string) $total : '0.00';
    }
}
