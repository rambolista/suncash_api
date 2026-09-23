<?php

namespace App\Services\Kiosk;

use App\Models\Mysuncash\KioskCashMeterTrx;
use App\Models\Mysuncash\KioskTerminal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "Kiosk > Confirm Customer Service" (legacy `fastpay::check_transaction()`
 * / `Fastpay_kiosk_model::get_kiosk_meters()` + `get_terminal_trx_details()`
 * — legacy calls this internally "dev customer service"). A read-only tool
 * to reconcile a kiosk's cash meter movements against its actual API call
 * log, to confirm whether a customer's claimed deposit/withdrawal really
 * happened. Legacy gated the whole menu item behind a hardcoded
 * `$_SESSION['UserName'] == "ace"` check instead of a real permission —
 * replaced here with the standard MODULE_PATH/can_view gate.
 *
 * Scope deliberately narrowed to the feature's PRIMARY/default view (legacy's
 * "Per Meter" mode): the list of raw deposit/withdraw meter events plus the
 * "Check Transaction" session-log drill-down. Legacy's secondary "Search
 * Type" dropdown (12 more modes — gaming, topup, card withdrawal, WU
 * send/receive, credit voucher, per-denomination, etc.) searches the SAME
 * underlying log tables by a different key and is not ported.
 *
 * Legacy only labels a row "withdraw" when `category === "REGULAR"` exactly,
 * so newer category variants seen in current data (`REGULAR_v2`,
 * `NEW_REGULAR`) fall through to its "deposit" default even when
 * `type === "out"` — a legacy bug from the category value evolving after
 * that string check was written. Not replicated: `type` (in/out, hardware
 * accept-vs-dispense) is what actually determines deposit vs. withdraw here,
 * regardless of category suffix.
 *
 * Legacy also re-derives each event's cash amount from the raw denomination
 * JSON via a running diff between consecutive same-terminal/type rows. The
 * `total_meter` column (added after that logic was written, always
 * populated in current data) already stores that same cumulative reading,
 * so the diff is computed directly off it instead of re-parsing JSON.
 * Per-denomination JSON parsing is still attempted for display, but only
 * understands the older flat-map shape (`{"BILL_ACCEPTOR_CURRENT_20":8}`);
 * a newer list shape (`[{"Denom":20,"Count":8,"Type":2}]`) exists on some
 * terminals and is reported as unavailable rather than guessed at — same
 * precedent as `KioskCashMeterService`.
 *
 * Legacy also lets staff pick which of three sharded API-call-log tables
 * (`api_calls`/`api_calls_2`/`api_calls_3`) to search — a storage-sharding
 * detail from the legacy system, not something a support agent should need
 * to know. This searches all three automatically instead.
 */
class KioskConfirmCustomerServiceService
{
    private const LOG_TABLES = ['api_calls', 'api_calls_2', 'api_calls_3'];

    public function listTerminals(): array
    {
        return KioskTerminal::where('status', KioskTerminal::STATUS_ACTIVE)
            ->orderBy('name')
            ->get(['id', 'name', 'location'])
            ->all();
    }

    public function list(string $dateFrom, string $dateTo, ?int $terminalId, int $limit): array
    {
        // Unwrapped timestamp range (not DATE(timestamp) BETWEEN ...) so this can use an index on timestamp.
        $query = KioskCashMeterTrx::query()
            ->whereIn('type', ['in', 'out'])
            ->where('timestamp', '>=', Carbon::parse($dateFrom)->startOfDay())
            ->where('timestamp', '<', Carbon::parse($dateTo)->addDay()->startOfDay());

        if ($terminalId) {
            $query->where('terminal_id', $terminalId);
        }

        $rows = $query->orderByDesc('timestamp')->limit($limit)
            ->get(['id', 'terminal_id', 'type', 'data', 'dispense_cassette', 'timestamp', 'session_id', 'total_meter'])
            ->sortBy('timestamp')
            ->values();

        $terminals = KioskTerminal::whereIn('id', $rows->pluck('terminal_id')->unique())
            ->get(['id', 'name', 'location'])->keyBy('id');

        $previousTotal = [];
        $events = [];
        foreach ($rows as $row) {
            $key = "{$row->terminal_id}:{$row->type}";
            $current = (float) $row->total_meter;
            $amount = array_key_exists($key, $previousTotal) ? abs($current - $previousTotal[$key]) : $current;
            $previousTotal[$key] = $current;

            $terminal = $terminals->get($row->terminal_id);

            $events[] = [
                'id' => $row->id,
                'created_date' => $row->timestamp,
                'terminal' => $terminal
                    ? strtoupper(trim("{$terminal->name} {$terminal->location}") ?: $terminal->name)
                    : 'UNKNOWN',
                'function' => $row->type === 'out' ? 'withdraw' : 'deposit',
                'amount' => round($amount, 2),
                'denominations' => $this->describeDenominations($row->type, $row->data, $row->dispense_cassette),
                'session_id' => $row->session_id,
                'session_available' => (bool) $row->session_id && $row->session_id !== '-1',
            ];
        }

        usort($events, fn ($a, $b) => strtotime($b['created_date']) <=> strtotime($a['created_date']));

        return $events;
    }

    /** Older flat-map JSON only ("denom => count"); newer list-shape JSON reports as unavailable rather than guessed at. */
    private function describeDenominations(string $type, ?string $data, ?string $cassetteJson): ?string
    {
        $decoded = json_decode((string) $data, true);
        if (! is_array($decoded) || $decoded === [] || array_is_list($decoded)) {
            return null;
        }

        $counts = [];

        if ($type === 'in') {
            foreach ($decoded as $key => $value) {
                if (preg_match('/^BILL_ACCEPTOR_CURRENT_(100|\d{1,2})$/', (string) $key, $m) && $value > 0) {
                    $counts[(int) $m[1]] = ($counts[(int) $m[1]] ?? 0) + $value;
                }
            }
        } else {
            $cassettes = json_decode((string) $cassetteJson, true);
            if (! is_array($cassettes) || $cassettes === []) {
                return null;
            }
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
        }

        if ($counts === []) {
            return null;
        }

        ksort($counts);

        return collect($counts)->map(fn ($count, $denom) => "{$denom}×{$count}")->implode(', ');
    }

    /** Port of legacy `get_terminal_trx_details()` — the "Check Transaction" drill-down for one kiosk session. */
    public function sessionLogs(string $sessionId): array
    {
        $login = null;
        $logTable = null;

        foreach (self::LOG_TABLES as $table) {
            $found = DB::connection('mysuncash')->table($table)
                ->select('id', 'timestamp')
                ->where('method', 'loginKiosk')
                ->where('output_from_backend', 'like', "%{$sessionId}%")
                ->orderByDesc('id')
                ->first();

            if ($found) {
                $login = $found;
                $logTable = $table;
                break;
            }
        }

        if (! $login) {
            return [];
        }

        $rows = DB::connection('mysuncash')->table($logTable)
            ->select('id', 'input_url', 'timestamp', 'method', 'output_from_backend as response')
            ->where('input_url', 'like', '%/api/kiosk.php?method=%')
            ->where('id', '>=', $login->id)
            ->where('timestamp', '>=', $login->timestamp)
            ->where('method', '!=', 'checkTransactionQrCode')
            ->orderBy('id')
            ->limit(100)
            ->get();

        $details = [];
        foreach ($rows as $row) {
            parse_str(str_replace("\n", '&', (string) parse_url($row->input_url, PHP_URL_QUERY)), $params);
            if (($params['P01'] ?? null) !== $sessionId) {
                continue;
            }

            unset($params['P01']);

            $details[] = [
                'trx_id' => $row->id,
                'trx_date' => $row->timestamp,
                'method' => $row->method,
                'params' => http_build_query($params),
                'response' => $row->response,
            ];
        }

        return $details;
    }
}
