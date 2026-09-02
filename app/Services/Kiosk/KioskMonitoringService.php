<?php

namespace App\Services\Kiosk;

use App\Models\ActivityLog;
use App\Models\Mysuncash\KioskMachineDetail;
use App\Models\Mysuncash\KioskTerminal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Kiosk > Monitoring Dashboard" — legacy `fastpay::dashboard()` /
 * `kiosk_machine_status_model::get_machine_status_details()`.
 *
 * Legacy determines ONLINE/OFFLINE by calling a third-party vendor API
 * ("Engage", `Device/DevicesWithCurrentStatus`) on every page load and
 * writing its verdict back into `kiosk_machine_details.status` — an
 * external, uncredentialed integration in this codebase (same category as
 * the Billpay/LocalGC/CenPOS gateways already excluded this session).
 *
 * That column is NOT solely written by the vendor sync, though: a
 * completely separate pipeline — the physical kiosk/ATM terminal's own
 * heartbeat, posted to a different legacy API (`services/kiosk.php`,
 * outside this codebase) and processed by a queue worker — writes the
 * SAME `status`/`update_date`/`offline_date` columns from the terminal's
 * own `CPU_HasInternetConnection` self-report. Both write to the live
 * `dev_mysuncash` database this app already reads. Since that heartbeat
 * pipeline runs independently of any admin viewing this page, reading
 * `kiosk_machine_details.status` directly (and having the frontend poll
 * this endpoint) gives genuinely live status without needing the vendor
 * integration at all — arguably more "real-time" than legacy's own
 * load-once, manual-refresh-only dashboard.
 *
 * Matches legacy's own inner-join behavior: only `kiosk_terminal` rows
 * with `status = 'A'` AND an existing `kiosk_machine_details` row (i.e.
 * terminals that have reported telemetry at least once) appear at all.
 */
class KioskMonitoringService
{
    public function list(): array
    {
        return DB::connection('mysuncash')->table('kiosk_terminal as kt')
            ->join('kiosk_machine_details as kcd', function ($join) {
                $join->on('kt.id', '=', 'kcd.kiosk_id')->where('kt.status', KioskTerminal::STATUS_ACTIVE);
            })
            ->join('kiosk_branch as kb', 'kb.id', '=', 'kt.kiosk_branch_id')
            ->leftJoin('island as i', 'i.id', '=', 'kt.island')
            ->orderByDesc('kcd.update_date')
            ->select(
                'kcd.id',
                'kt.id as terminal_id',
                'kt.code as terminal_code',
                'kt.name as machine_name',
                'kt.location',
                'kt.terminal_type',
                'kt.cash_total_reserve as cash_reserve',
                'kb.name as branch_name',
                'i.name as island_name',
                'kcd.status',
                'kcd.paper',
                'kcd.acceptor',
                'kcd.dispenser',
                'kcd.recycler',
                'kcd.acceptor_cash',
                'kcd.dispenser_cash',
                'kcd.is_acknowledge',
                'kcd.updated_by',
                'kcd.update_date',
                'kcd.offline_date',
            )
            ->get()
            ->map(fn ($row) => $this->present($row))
            ->all();
    }

    private function present(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'terminal_id' => (int) $row->terminal_id,
            'terminal_code' => $row->terminal_code,
            'machine_name' => $row->machine_name,
            'location' => $row->location,
            'terminal_type' => $row->terminal_type,
            'cash_reserve' => (float) $row->cash_reserve,
            'branch_name' => $row->branch_name,
            'island_name' => $row->island_name,
            'status' => strtoupper((string) $row->status) === KioskMachineDetail::STATUS_OK ? 'online' : 'offline',
            'paper' => $row->paper ?: null,
            'acceptor' => $row->acceptor ?: null,
            'dispenser' => $row->dispenser ?: null,
            'recycler' => $row->recycler ?: null,
            'acceptor_cash' => (float) $row->acceptor_cash,
            'dispenser_cash' => (float) $row->dispenser_cash,
            'is_acknowledged' => (string) $row->is_acknowledge === '1',
            'updated_by' => $row->updated_by,
            'last_seen' => $row->update_date,
            'offline_date' => $row->offline_date,
        ];
    }

    /**
     * @throws ValidationException
     */
    private function findOrFail(int $id): KioskMachineDetail
    {
        $detail = KioskMachineDetail::find($id);
        if (! $detail) {
            throw ValidationException::withMessages(['id' => ['This terminal has not been updated from the kiosk yet.']]);
        }

        return $detail;
    }

    /**
     * Legacy's "CLEAR" button — marks status/paper/acceptor/dispenser OK and
     * resets the acknowledge flag. Deliberately does NOT replicate legacy's
     * extra cash-figure auto-adjustment (a LOW/HIGH cash-level heuristic
     * with a merchant-cash-vs-dispenser cross-reference that reads as a
     * legacy copy-paste bug, not a clearly-intended rule) — cash totals are
     * left untouched here, which the same backend update path already
     * supports (those fields are only written when supplied).
     *
     * @throws ValidationException
     */
    public function clear(int $id, string $actorId, string $actorName): array
    {
        $detail = $this->findOrFail($id);
        $terminal = $detail->terminal;

        $detail->update([
            'status' => KioskMachineDetail::STATUS_OK,
            'paper' => KioskMachineDetail::STATUS_OK,
            'acceptor' => KioskMachineDetail::STATUS_OK,
            'dispenser' => KioskMachineDetail::STATUS_OK,
            'is_acknowledge' => '0',
            'updated_by' => $actorName,
            'update_date' => now(),
        ]);

        ActivityLog::recordAction(User::find($actorId), 'Kiosk Monitoring', 'cleared', 'Cleared status for kiosk '.($terminal?->code ?? "#{$id}"), $detail, null);

        return ['message' => 'Machine status has been updated.'];
    }

    /**
     * Legacy's "ACKNOWLEDGE" button — marks the current alert as seen,
     * without changing status/paper/acceptor/dispenser.
     *
     * @throws ValidationException
     */
    public function acknowledge(int $id, string $actorId, string $actorName): array
    {
        $detail = $this->findOrFail($id);
        $terminal = $detail->terminal;

        $detail->update([
            'is_acknowledge' => '1',
            'updated_by' => $actorName,
            'update_date' => now(),
        ]);

        ActivityLog::recordAction(User::find($actorId), 'Kiosk Monitoring', 'acknowledged', 'Acknowledged alert for kiosk '.($terminal?->code ?? "#{$id}"), $detail, null);

        return ['message' => 'Machine status has been updated.'];
    }
}
