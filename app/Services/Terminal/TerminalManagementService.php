<?php

namespace App\Services\Terminal;

use App\Models\Mysuncash\Merchant;
use App\Models\Mysuncash\Terminal;
use Illuminate\Validation\ValidationException;

/**
 * The global "Terminals Management" admin screen (legacy `Administrator::
 * terminal_management_list()` / `terminals_model.php`) — lists and manages
 * every merchant's registered device from one cross-merchant table, unlike
 * the per-merchant "Terminals" tab on Merchant Registration
 * (App\Services\Merchant\MerchantTerminalService), which this deliberately
 * does not replace; both operate on the same `terminals` table.
 *
 * Legacy status semantics carried over as-is: `device_status_id` 0=active,
 * 1=inactive, 2=deactive (a soft delete — the row is never actually removed,
 * just excluded from the `whereIn(device_status_id, [0,1])` lists/uniqueness
 * checks everywhere, matching `terminal_status_change()`).
 *
 * Two legacy gaps are deliberately NOT reproduced: (1) device_id uniqueness
 * is re-checked here on update too (legacy's `terminal_update_2()` never
 * re-validated it, allowing silent duplicates); (2) branch/lane/counter/
 * serial-key fields are intentionally omitted — the legacy menu-driven add/
 * edit forms this screen replicates (`terminal_add_form_with_merchant`,
 * `terminal_details_form_editableID`) never exposed them either; those stay
 * exclusive to the per-merchant Registration tab's fuller form.
 */
class TerminalManagementService
{
    private function present(Terminal $terminal): array
    {
        $merchant = $terminal->merchant;

        return [
            'id' => $terminal->id,
            'merchant_id' => $terminal->client_id,
            'suntag_shortcode' => $merchant?->client_id,
            'merchant_name' => $merchant?->merchant_name ?: $merchant?->dba_name,
            'entity_type' => Merchant::ENTITY_TYPES[(int) $merchant?->reseller_type] ?? null,
            'device_id' => $terminal->device_id,
            'device_type_id' => (int) $terminal->device_type_id,
            'device_type' => Terminal::DEVICE_TYPES[(int) $terminal->device_type_id] ?? null,
            'brand_name' => $terminal->brand_name,
            'model' => $terminal->model,
            'connection_type_id' => (int) $terminal->connection_type_id,
            'connection_type' => Terminal::CONNECTION_TYPES[(int) $terminal->connection_type_id] ?? null,
            'status_id' => (int) $terminal->device_status_id,
            'status' => Terminal::STATUSES[(int) $terminal->device_status_id] ?? 'active',
            'created_at' => $terminal->creation_date,
        ];
    }

    public function list(): array
    {
        return Terminal::with('merchant')
            ->whereIn('device_status_id', [0, 1])
            ->orderByDesc('id')
            ->get()
            ->map(fn (Terminal $terminal) => $this->present($terminal))
            ->all();
    }

    /**
     * The merchant picker for the Add Terminal form. Legacy loads every
     * merchant into one giant `<select>` (`merchants_model->merchant_list()`)
     * with no search; this instead returns every active merchant once and
     * lets the frontend filter client-side — same approach already used for
     * Merchant Statement's merchant picker.
     */
    public function listMerchantsForPicker(): array
    {
        return Merchant::where('registration_status', 'A')
            ->orderBy('merchant_name')
            ->get(['id', 'client_id', 'merchant_name', 'dba_name'])
            ->map(fn (Merchant $m) => [
                'id' => $m->id,
                'suntag_shortcode' => $m->client_id,
                'name' => $m->merchant_name ?: $m->dba_name,
            ])
            ->all();
    }

    /**
     * @throws ValidationException
     */
    private function findOrFail(int $id): Terminal
    {
        $terminal = Terminal::find($id);
        if (! $terminal) {
            throw ValidationException::withMessages(['id' => ['Terminal not found.']]);
        }

        return $terminal;
    }

    private function validateDeviceFields(array $data): array
    {
        $errors = [];

        if (! filled($data['device_id'] ?? null)) {
            $errors['device_id'] = ['Device ID is required.'];
        }
        if (! filled($data['brand_name'] ?? null)) {
            $errors['brand_name'] = ['Brand name is required.'];
        }
        if (! filled($data['model'] ?? null)) {
            $errors['model'] = ['Model is required.'];
        }
        if (! array_key_exists((int) ($data['device_type_id'] ?? 0), Terminal::DEVICE_TYPES)) {
            $errors['device_type_id'] = ['Select a device type.'];
        }
        if (! array_key_exists((int) ($data['connection_type_id'] ?? 0), Terminal::CONNECTION_TYPES)) {
            $errors['connection_type_id'] = ['Select a connection type.'];
        }

        return $errors;
    }

    /**
     * @throws ValidationException
     */
    public function create(array $data, string $actorId): array
    {
        $errors = $this->validateDeviceFields($data);

        $merchant = Merchant::find($data['merchant_id'] ?? null);
        if (! $merchant) {
            $errors['merchant_id'] = ['Select a merchant.'];
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $deviceId = trim((string) $data['device_id']);
        $taken = Terminal::where('device_id', $deviceId)->whereIn('device_status_id', [0, 1])->exists();
        if ($taken) {
            throw ValidationException::withMessages(['device_id' => ['This device ID is already registered.']]);
        }

        $now = now();
        $terminal = Terminal::create([
            'client_id' => $merchant->id,
            'device_id' => $deviceId,
            'device_status_id' => 0,
            'device_type_id' => $data['device_type_id'],
            'brand_name' => trim((string) $data['brand_name']),
            'model' => trim((string) $data['model']),
            'connection_type_id' => $data['connection_type_id'],
            'user_id_create' => $actorId,
            'user_id_modify' => $actorId,
            'creation_date' => $now,
            'modification_date' => $now,
        ]);

        return $this->present($terminal->load('merchant'));
    }

    /**
     * @throws ValidationException
     */
    public function update(int $id, array $data, string $actorId): array
    {
        $terminal = $this->findOrFail($id);
        $errors = $this->validateDeviceFields($data);
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $deviceId = trim((string) $data['device_id']);
        $taken = Terminal::where('device_id', $deviceId)
            ->where('id', '!=', $terminal->id)
            ->whereIn('device_status_id', [0, 1])
            ->exists();
        if ($taken) {
            throw ValidationException::withMessages(['device_id' => ['This device ID is already registered.']]);
        }

        $terminal->update([
            'device_id' => $deviceId,
            'device_type_id' => $data['device_type_id'],
            'brand_name' => trim((string) $data['brand_name']),
            'model' => trim((string) $data['model']),
            'connection_type_id' => $data['connection_type_id'],
            'user_id_modify' => $actorId,
            'modification_date' => now(),
        ]);

        return $this->present($terminal->load('merchant'));
    }

    /**
     * @throws ValidationException
     */
    public function changeStatus(int $id, int $statusId, string $actorId): array
    {
        if (! array_key_exists($statusId, Terminal::STATUSES)) {
            throw ValidationException::withMessages(['status' => ['Invalid terminal status.']]);
        }

        $terminal = $this->findOrFail($id);
        $terminal->update([
            'device_status_id' => $statusId,
            'user_id_modify' => $actorId,
            'modification_date' => now(),
        ]);

        return ['id' => $terminal->id, 'status' => Terminal::STATUSES[$statusId]];
    }
}
