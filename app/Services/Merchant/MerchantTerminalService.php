<?php

namespace App\Services\Merchant;

use App\Models\Mysuncash\Merchant;
use App\Models\Mysuncash\Terminal;
use Illuminate\Validation\ValidationException;

class MerchantTerminalService
{
    private function findMerchantOrFail(int $merchantId): Merchant
    {
        $merchant = Merchant::find($merchantId);
        if (! $merchant) {
            throw ValidationException::withMessages(['id' => ['Merchant not found.']]);
        }

        return $merchant;
    }

    private function findTerminalOrFail(int $merchantId, int $terminalId): Terminal
    {
        $terminal = Terminal::where('client_id', $merchantId)->where('id', $terminalId)->first();
        if (! $terminal) {
            throw ValidationException::withMessages(['id' => ['Terminal not found.']]);
        }

        return $terminal;
    }

    private function present(Terminal $terminal): array
    {
        return [
            'id' => $terminal->id,
            'device_id' => $terminal->device_id,
            'device_alias' => $terminal->device_alias,
            'status' => Terminal::STATUSES[(int) $terminal->device_status_id] ?? 'active',
            'device_type' => Terminal::DEVICE_TYPES[(int) $terminal->device_type_id] ?? null,
            'device_type_id' => $terminal->device_type_id,
            'connection_type' => Terminal::CONNECTION_TYPES[(int) $terminal->connection_type_id] ?? null,
            'connection_type_id' => $terminal->connection_type_id,
            'brand_name' => $terminal->brand_name,
            'model' => $terminal->model,
            'lane_counter' => $terminal->lane_counter,
            'counter_no' => $terminal->counter_no,
            'branch_id' => $terminal->branch_id,
        ];
    }

    public function listTerminals(int $merchantId): array
    {
        return Terminal::where('client_id', $merchantId)
            ->whereIn('device_status_id', [0, 1])
            ->orderBy('device_id')
            ->get()
            ->map(fn (Terminal $terminal) => $this->present($terminal))
            ->all();
    }

    /**
     * @throws ValidationException
     */
    public function createTerminal(int $merchantId, array $data, string $actorId): array
    {
        $this->findMerchantOrFail($merchantId);

        $deviceId = trim((string) ($data['device_id'] ?? ''));
        $errors = [];
        if (! filled($deviceId)) {
            $errors['device_id'] = ['Device ID is required.'];
        }
        if (! array_key_exists((int) ($data['device_type_id'] ?? 0), Terminal::DEVICE_TYPES)) {
            $errors['device_type_id'] = ['Select a device type.'];
        }
        if (! array_key_exists((int) ($data['connection_type_id'] ?? 0), Terminal::CONNECTION_TYPES)) {
            $errors['connection_type_id'] = ['Select a connection type.'];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $taken = Terminal::where('device_id', $deviceId)->whereIn('device_status_id', [0, 1])->exists();
        if ($taken) {
            throw ValidationException::withMessages(['device_id' => ['This device ID is already registered.']]);
        }

        $now = now();
        $terminal = Terminal::create([
            'client_id' => $merchantId,
            'device_id' => $deviceId,
            'device_alias' => $data['device_alias'] ?? null,
            'device_status_id' => 0,
            'device_type_id' => $data['device_type_id'],
            'brand_name' => trim((string) ($data['brand_name'] ?? '')),
            'model' => trim((string) ($data['model'] ?? '')),
            'connection_type_id' => $data['connection_type_id'],
            'lane_counter' => $data['lane_counter'] ?? null,
            'counter_no' => $data['counter_no'] ?? null,
            'branch_id' => $data['branch_id'] ?? null,
            'user_id_create' => $actorId,
            'user_id_modify' => $actorId,
            'creation_date' => $now,
            'modification_date' => $now,
        ]);

        return $this->present($terminal);
    }

    /**
     * @throws ValidationException
     */
    public function updateTerminal(int $merchantId, int $terminalId, array $data, string $actorId): array
    {
        $terminal = $this->findTerminalOrFail($merchantId, $terminalId);

        $terminal->update([
            'device_alias' => $data['device_alias'] ?? $terminal->device_alias,
            'device_type_id' => $data['device_type_id'] ?? $terminal->device_type_id,
            'brand_name' => $data['brand_name'] ?? $terminal->brand_name,
            'model' => $data['model'] ?? $terminal->model,
            'connection_type_id' => $data['connection_type_id'] ?? $terminal->connection_type_id,
            'lane_counter' => $data['lane_counter'] ?? $terminal->lane_counter,
            'counter_no' => $data['counter_no'] ?? $terminal->counter_no,
            'branch_id' => $data['branch_id'] ?? $terminal->branch_id,
            'user_id_modify' => $actorId,
            'modification_date' => now(),
        ]);

        return $this->present($terminal);
    }

    /**
     * @throws ValidationException
     */
    public function changeTerminalStatus(int $merchantId, int $terminalId, int $statusId): array
    {
        if (! in_array($statusId, [0, 1, 2], true)) {
            throw ValidationException::withMessages(['status' => ['Invalid terminal status.']]);
        }

        $terminal = $this->findTerminalOrFail($merchantId, $terminalId);
        $terminal->update(['device_status_id' => $statusId, 'modification_date' => now()]);

        return ['id' => $terminal->id, 'status' => Terminal::STATUSES[$statusId]];
    }
}
