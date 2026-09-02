<?php

namespace App\Services\Kiosk;

use App\Models\Mysuncash\KioskBranch;
use App\Models\Mysuncash\KioskCashMeterTrx;
use App\Models\Mysuncash\KioskTerminal;

/**
 * "Kiosk > Cash Meters (Transaction)" (legacy `fastpay::cash_meters()` /
 * `get_kiosk_meters()` / `get_terminals_by_branch()` — the controller
 * lives in `fastpay.php`, not `administrator.php`, unlike most other
 * Kiosk features). A pure lookup tool: pick a branch, a terminal, and a
 * meter type (Acceptor/Dispenser), and see the LATEST recorded
 * `kiosk_cash_meters_trx` row for that terminal+type, decoded from its
 * `data`/`dispense_cassette` JSON into a per-denomination breakdown.
 *
 * Legacy's own branch_id filter on the lookup query is a no-op bug (it
 * runs the WHERE against a different DB connection object than the one
 * actually queried) — not replicated as a query filter here, since it's
 * also functionally moot: the Terminal dropdown already only offers
 * terminals from the selected branch, so a terminal_id picked through the
 * UI is already branch-scoped by construction.
 *
 * Two further legacy bugs in the "Dispenser" (type=out) breakdown are
 * deliberately NOT replicated, since both are unambiguous copy/paste
 * mistakes rather than intended behavior: (1) the per-row HTML repeats the
 * Lifetime column a second time instead of showing the already-computed
 * Service count/value; (2) the response's overall `total_service_*` and
 * `total_reject_val` fields are literally assigned from the wrong
 * accumulator variable (`$total_reject_cnt`) instead of their own. This
 * port surfaces the genuinely-computed Service column and correct totals
 * instead, matching the "Acceptor" (type=in) table's already-correct
 * shape (Denom / Service / Current / Lifetime, plus Reject for Dispenser).
 *
 * Also NOT replicated: legacy's parser only understands one JSON shape for
 * `data` (a flat `{"BILL_ACCEPTOR_CURRENT_20": 8, ...}` key/count map).
 * A newer shape (`[{"Denom":20,"Count":8,"Type":2}, ...]`) exists on some
 * terminals' latest rows and legacy silently mis-renders those as a single
 * bogus "denomination 0, all zero" row. This port detects that shape and
 * simply reports no parseable data instead of fabricating a zero row.
 */
class KioskCashMeterService
{
    public const TRANSACTION_TYPES = [
        'in' => 'Acceptor',
        'out' => 'Dispenser',
    ];

    public function listBranches(): array
    {
        return KioskBranch::where('status', KioskBranch::STATUS_ACTIVE)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->all();
    }

    /**
     * Legacy quirk, replicated deliberately: the initial page-load terminal
     * list is active-only; once a branch is picked, `get_terminals_by_branch`
     * repopulates the dropdown with terminals of ANY status in that branch.
     */
    public function listTerminals(?int $branchId = null): array
    {
        $query = KioskTerminal::query();
        if ($branchId) {
            $query->where('kiosk_branch_id', $branchId);
        } else {
            $query->where('status', KioskTerminal::STATUS_ACTIVE);
        }

        return $query->orderBy('name')->get(['id', 'name', 'kiosk_branch_id', 'status'])->all();
    }

    private function emptyBucket(string $denom): array
    {
        return [
            'denom' => $denom,
            'current_count' => 0, 'current_value' => 0,
            'service_count' => 0, 'service_value' => 0,
            'reject_count' => 0, 'reject_value' => 0,
            'lifetime_count' => 0, 'lifetime_value' => 0,
        ];
    }

    private function isFlatMap(mixed $decoded): bool
    {
        return is_array($decoded) && $decoded !== [] && ! array_is_list($decoded);
    }

    /** Acceptor (`type=in`) — one denomination bucket per `BILL_ACCEPTOR_CURRENT_*`/`SVC_BILL_ACCEPTOR_IN_*`/`LIFETIME_BILLS_IN_*` key. */
    private function parseAcceptor(?string $json): array
    {
        $decoded = json_decode((string) $json, true);
        if (! $this->isFlatMap($decoded)) {
            return ['rows' => [], 'totals' => $this->emptyBucket('Total')];
        }

        $buckets = [];
        foreach ($decoded as $key => $value) {
            if (preg_match('/^BILL_ACCEPTOR_CURRENT_(100|\d{1,2})$/', $key, $m)) {
                $denom = $m[1];
                $buckets[$denom] ??= $this->emptyBucket($denom);
                $buckets[$denom]['current_count'] += $value;
                $buckets[$denom]['current_value'] = (int) $denom * $buckets[$denom]['current_count'];
            } elseif (preg_match('/^SVC_BILL_ACCEPTOR_IN_(100|\d{1,2})$/', $key, $m)) {
                $denom = $m[1];
                $buckets[$denom] ??= $this->emptyBucket($denom);
                $buckets[$denom]['service_count'] += $value;
                $buckets[$denom]['service_value'] = (int) $denom * $buckets[$denom]['service_count'];
            } elseif (preg_match('/^LIFETIME_BILLS_IN_(100|\d{1,2})$/', $key, $m)) {
                $denom = $m[1];
                $buckets[$denom] ??= $this->emptyBucket($denom);
                $buckets[$denom]['lifetime_count'] += $value;
                $buckets[$denom]['lifetime_value'] = (int) $denom * $buckets[$denom]['lifetime_count'];
            }
        }

        ksort($buckets, SORT_NUMERIC);

        return ['rows' => array_values($buckets), 'totals' => $this->sumTotals($buckets)];
    }

    /** Dispenser (`type=out`) — cassette `Identifier` maps to a `Denomination`; keys suffixed with that identifier feed current/reject/lifetime/service. */
    private function parseDispenser(?string $json, ?string $cassetteJson): array
    {
        $data = json_decode((string) $json, true);
        $cassettes = json_decode((string) $cassetteJson, true);
        if (! $this->isFlatMap($data) || ! is_array($cassettes) || $cassettes === []) {
            return ['rows' => [], 'totals' => $this->emptyBucket('Total')];
        }

        $buckets = [];
        foreach ($data as $key => $value) {
            foreach ($cassettes as $cassette) {
                $identifier = $cassette['Identifier'] ?? null;
                $denom = (string) ($cassette['Denomination'] ?? '');
                if ($identifier === null || $denom === '') {
                    continue;
                }

                if (str_contains($key, "BILL_DISPENSER_CURRENT_{$identifier}")) {
                    $buckets[$identifier] ??= $this->emptyBucket($denom);
                    $buckets[$identifier]['current_count'] += $value;
                    $buckets[$identifier]['current_value'] = (int) $denom * $buckets[$identifier]['current_count'];
                } elseif (str_contains($key, "CASH_REJECT_BIN_{$identifier}")) {
                    $buckets[$identifier] ??= $this->emptyBucket($denom);
                    $buckets[$identifier]['reject_count'] += $value;
                    $buckets[$identifier]['reject_value'] = (int) $denom * $buckets[$identifier]['reject_count'];
                } elseif (str_contains($key, "LIFETIME_BILLS_OUT_{$identifier}")) {
                    $buckets[$identifier] ??= $this->emptyBucket($denom);
                    $buckets[$identifier]['lifetime_count'] += $value;
                    $buckets[$identifier]['lifetime_value'] = (int) $denom * $buckets[$identifier]['lifetime_count'];
                } elseif (str_contains($key, "SVC_BILL_DISPENSER_OUT_{$identifier}")) {
                    $buckets[$identifier] ??= $this->emptyBucket($denom);
                    $buckets[$identifier]['service_count'] += $value;
                    $buckets[$identifier]['service_value'] = (int) $denom * $buckets[$identifier]['service_count'];
                }
            }
        }

        uasort($buckets, fn ($a, $b) => $a['denom'] <=> $b['denom']);

        return ['rows' => array_values($buckets), 'totals' => $this->sumTotals($buckets)];
    }

    private function sumTotals(array $buckets): array
    {
        $totals = $this->emptyBucket('Total');
        foreach ($buckets as $bucket) {
            foreach (['current_count', 'current_value', 'service_count', 'service_value', 'reject_count', 'reject_value', 'lifetime_count', 'lifetime_value'] as $field) {
                $totals[$field] += $bucket[$field];
            }
        }

        return $totals;
    }

    public function meters(int $terminalId, string $type): array
    {
        if (! array_key_exists($type, self::TRANSACTION_TYPES)) {
            $type = 'in';
        }

        $query = KioskCashMeterTrx::where('terminal_id', $terminalId)
            ->where('type', $type)
            ->whereNotNull('data')
            ->where('data', '!=', '{}');

        if ($type === 'out') {
            $query->whereNotNull('dispense_cassette')->where('dispense_cassette', '!=', '{}');
        }

        $latest = $query->orderByDesc('id')->first();

        if (! $latest) {
            return ['type' => $type, 'rows' => [], 'totals' => null, 'has_data' => false];
        }

        $parsed = $type === 'in'
            ? $this->parseAcceptor($latest->data)
            : $this->parseDispenser($latest->data, $latest->dispense_cassette);

        return [
            'type' => $type,
            'rows' => $parsed['rows'],
            'totals' => $parsed['totals'],
            'has_data' => $parsed['rows'] !== [],
        ];
    }
}
