<?php

namespace App\Services\Kiosk;

use App\Models\ActivityLog;
use App\Models\Mysuncash\Island;
use App\Models\Mysuncash\KioskBranch;
use App\Models\Mysuncash\KioskManager;
use App\Models\Mysuncash\KioskProfile;
use App\Models\Mysuncash\KioskTerminal;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * "Kiosk Management > Kiosk Terminals" (legacy `administrator::
 * kiosk_terminal_list()` / `kiosk_register_terminal()` /
 * `kiosk_update_terminal()` / `kiosk_delete_terminal()` /
 * `kiosk_commission_settings()`). Legacy's separate "Commission Settings"
 * quick-action and the commission fields bundled into the full Edit form
 * both write the same three `kiosk_terminal` columns — both entry points
 * here call the same `updateCommission()`.
 *
 * Deliberately NOT ported from the legacy "Kiosk Terminals" row actions:
 * "Credit Voucher" (a money-movement action) and "View/Add Managers" (a
 * separate CRUD screen) — both are flagged as follow-up scope decisions.
 * The Manager picker below is read-only against whatever `kiosk_managers`
 * rows already exist, matching legacy's own unfiltered (cross-branch) list.
 */
class KioskTerminalService
{
    private function present(KioskTerminal $terminal): array
    {
        $manager = $terminal->manager;

        return [
            'id' => $terminal->id,
            'kiosk_branch_id' => $terminal->kiosk_branch_id,
            'code' => $terminal->code,
            'name' => $terminal->name,
            'device_id' => $terminal->device_id,
            'username' => $terminal->username,
            'location' => $terminal->location,
            'island_id' => $terminal->island,
            'island_name' => $terminal->islandRecord?->name,
            'terminal_type' => $terminal->terminal_type,
            'acceptor_high_alert' => (float) $terminal->acceptor_high_alert,
            'dispenser_low_alert' => (float) $terminal->dispenser_low_alert,
            'manager_id' => $terminal->manager_id,
            'manager_name' => $manager ? trim($manager->manager_firstname.' '.$manager->manager_lastname) : null,
            'commission_type' => (int) $terminal->commission_type,
            'commission_type_label' => KioskTerminal::COMMISSION_TYPES[(int) $terminal->commission_type] ?? 'No Commission',
            'commission_fixed_value' => (float) $terminal->commission_fixed_value,
            'profile_id' => $terminal->profile_id,
            'create_date' => $terminal->create_date,
        ];
    }

    public function list(int $branchId): array
    {
        return KioskTerminal::with(['manager', 'islandRecord'])
            ->where('status', KioskTerminal::STATUS_ACTIVE)
            ->where('kiosk_branch_id', $branchId)
            ->orderByDesc('id')
            ->get()
            ->map(fn (KioskTerminal $terminal) => $this->present($terminal))
            ->all();
    }

    public function listIslands(): array
    {
        return Island::orderBy('name')->get(['id', 'name'])->all();
    }

    /** Legacy's `get_kiosk_managers()` with no args: every active manager with details, unfiltered by branch. */
    public function listManagers(): array
    {
        return KioskManager::whereHas('details')
            ->where('status', 'active')
            ->orderBy('manager_firstname')
            ->get(['id', 'manager_firstname', 'manager_lastname', 'branch_id'])
            ->map(fn (KioskManager $m) => ['id' => $m->id, 'name' => trim($m->manager_firstname.' '.$m->manager_lastname)])
            ->all();
    }

    public function listCommissionProfiles(): array
    {
        return KioskProfile::distinct()->orderBy('profile_name')->pluck('profile_name')->filter()->values()->all();
    }

    /**
     * @throws ValidationException
     */
    private function findOrFail(int $id): KioskTerminal
    {
        $terminal = KioskTerminal::where('status', KioskTerminal::STATUS_ACTIVE)->find($id);
        if (! $terminal) {
            throw ValidationException::withMessages(['id' => ['This kiosk terminal was not found.']]);
        }

        return $terminal;
    }

    /** `device_id` is intentionally excluded — legacy's edit form never re-collects it, only Add does. */
    private function validateCoreFields(array $data, ?int $ignoreTerminalId = null): array
    {
        $errors = [];

        foreach (['code' => 'a terminal code', 'name' => 'a terminal name', 'username' => 'a username', 'location' => 'kiosk location'] as $field => $label) {
            if (! filled($data[$field] ?? null)) {
                $errors[$field] = ["Please enter {$label}."];
            }
        }

        if (! filled($data['island'] ?? null)) {
            $errors['island'] = ['Please select your island.'];
        }

        $type = strtolower((string) ($data['terminal_type'] ?? ''));
        if (! in_array($type, ['kiosk', 'atm'], true)) {
            $errors['terminal_type'] = ['Please select a terminal type.'];
        } else {
            $acceptor = $data['acceptor_high_alert'] ?? null;
            if (! is_numeric($acceptor) || (float) $acceptor <= 0) {
                $errors['acceptor_high_alert'] = ['Please enter a valid acceptor value.'];
            }
            if ($type === 'atm') {
                $dispenser = $data['dispenser_low_alert'] ?? null;
                if (! is_numeric($dispenser) || (float) $dispenser <= 0) {
                    $errors['dispenser_low_alert'] = ['Please enter a valid dispenser value.'];
                }
            }
        }

        if (! $errors && filled($data['code'] ?? null)) {
            $code = trim((string) $data['code']);
            $query = KioskTerminal::where('status', KioskTerminal::STATUS_ACTIVE)
                ->where('kiosk_branch_id', $data['kiosk_branch_id'])
                ->whereRaw('UPPER(code) = ?', [strtoupper($code)]);
            if ($ignoreTerminalId) {
                $query->where('id', '!=', $ignoreTerminalId);
            }
            if ($query->exists()) {
                $errors['code'] = ['Kiosk Terminal Code already used.'];
            }
        }

        return $errors;
    }

    /**
     * @throws ValidationException
     */
    public function create(array $data, User $actor): array
    {
        if (! KioskBranch::where('status', KioskBranch::STATUS_ACTIVE)->where('id', $data['kiosk_branch_id'] ?? null)->exists()) {
            throw ValidationException::withMessages(['kiosk_branch_id' => ['This kiosk branch was not found.']]);
        }

        $errors = $this->validateCoreFields($data);
        if (! filled($data['device_id'] ?? null)) {
            $errors['device_id'] = ['Please enter a device id.'];
        }
        if (! filled($data['password'] ?? null)) {
            $errors['password'] = ['Please enter a password.'];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $terminal = KioskTerminal::create([
            'kiosk_branch_id' => $data['kiosk_branch_id'],
            'code' => trim((string) $data['code']),
            'name' => trim((string) $data['name']),
            'device_id' => trim((string) $data['device_id']),
            'username' => trim((string) $data['username']),
            'password' => md5(trim((string) $data['password'])),
            'location' => trim((string) $data['location']),
            'island' => $data['island'],
            'terminal_type' => strtolower((string) $data['terminal_type']),
            'acceptor_high_alert' => $data['acceptor_high_alert'],
            'dispenser_low_alert' => $data['dispenser_low_alert'] ?? 0,
            'manager_id' => filled($data['manager_id'] ?? null) ? $data['manager_id'] : null,
            'status' => KioskTerminal::STATUS_ACTIVE,
            'create_by' => $actor->name ?? $actor->email,
            'create_date' => now(),
        ]);

        ActivityLog::recordAction($actor, 'Kiosk Management', 'created_terminal', "Registered kiosk terminal {$terminal->code} ({$terminal->name})", $terminal);

        return $this->present($terminal->load(['manager', 'islandRecord']));
    }

    /**
     * @throws ValidationException
     */
    public function update(int $id, array $data, User $actor): array
    {
        $terminal = $this->findOrFail($id);
        $data['kiosk_branch_id'] = $terminal->kiosk_branch_id;

        $errors = $this->validateCoreFields($data, $terminal->id);
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $update = [
            'code' => trim((string) $data['code']),
            'name' => trim((string) $data['name']),
            'username' => trim((string) $data['username']),
            'location' => trim((string) $data['location']),
            'island' => $data['island'],
            'terminal_type' => strtolower((string) $data['terminal_type']),
            'acceptor_high_alert' => $data['acceptor_high_alert'],
            'dispenser_low_alert' => $data['dispenser_low_alert'] ?? 0,
            'manager_id' => filled($data['manager_id'] ?? null) ? $data['manager_id'] : null,
            'update_by' => $actor->name ?? $actor->email,
            'update_date' => now(),
        ];

        if (filled($data['password'] ?? null)) {
            $update['password'] = md5(trim((string) $data['password']));
        }

        $terminal->update($update);

        ActivityLog::recordAction($actor, 'Kiosk Management', 'updated_terminal', "Updated kiosk terminal {$terminal->code} ({$terminal->name})", $terminal);

        return $this->present($terminal->load(['manager', 'islandRecord']));
    }

    /**
     * @throws ValidationException
     */
    public function updateCommission(int $id, array $data, User $actor): array
    {
        $terminal = $this->findOrFail($id);

        $type = (string) ($data['commission_type'] ?? '');
        $errors = [];
        if (! array_key_exists((int) $type, KioskTerminal::COMMISSION_TYPES)) {
            $errors['commission_type'] = ['Please select a commission type.'];
        } elseif (
            ($type === '1' && ! filled($data['commission_fixed_value'] ?? null))
            || ($type === '2' && ! filled($data['profile_id'] ?? null))
            || (in_array($type, ['3', '4'], true) && (! filled($data['profile_id'] ?? null) || ! filled($data['commission_fixed_value'] ?? null)))
        ) {
            $errors['commission_type'] = ['Please check your commission setting details.'];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $update = ['update_by' => $actor->name ?? $actor->email, 'update_date' => now(), 'commission_type' => (int) $type];
        if ($type === '1') {
            $update['commission_fixed_value'] = $data['commission_fixed_value'];
            $update['profile_id'] = '';
        } elseif ($type === '2') {
            $update['profile_id'] = $data['profile_id'];
            $update['commission_fixed_value'] = 0;
        } else {
            $update['profile_id'] = $data['profile_id'];
            $update['commission_fixed_value'] = $data['commission_fixed_value'];
        }

        $terminal->update($update);

        ActivityLog::recordAction($actor, 'Kiosk Management', 'updated_commission', "Updated commission settings for kiosk terminal {$terminal->code}", $terminal);

        return $this->present($terminal->load(['manager', 'islandRecord']));
    }

    /**
     * @throws ValidationException
     */
    public function delete(int $id, User $actor): void
    {
        $terminal = $this->findOrFail($id);

        $terminal->update([
            'status' => KioskTerminal::STATUS_DELETED,
            'update_by' => $actor->name ?? $actor->email,
            'update_date' => now(),
        ]);

        ActivityLog::recordAction($actor, 'Kiosk Management', 'deleted_terminal', "Deleted kiosk terminal {$terminal->code} ({$terminal->name})", $terminal);
    }
}
