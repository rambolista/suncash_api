<?php

namespace App\Services\Kiosk;

use App\Models\ActivityLog;
use App\Models\Mysuncash\KioskBranch;
use App\Models\Mysuncash\Merchant;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * "Kiosk > Kiosk Management" branch list (legacy `administrator::
 * kiosk_management_list()` / `kiosk_model::get_kiosk_branch()` /
 * `kiosk_register()` / `kiosk_delete()`). Legacy has no branch-edit screen
 * at all — only Add and (soft) Delete — so this deliberately doesn't add one.
 */
class KioskBranchService
{
    private function present(KioskBranch $branch): array
    {
        $merchant = $branch->merchant;

        return [
            'id' => $branch->id,
            'merchant_id' => $branch->client_id,
            'merchant_code' => $merchant?->client_id,
            'merchant_name' => $merchant?->legal_name,
            'code' => $branch->code,
            'name' => $branch->name,
            'address' => $branch->address,
            'city' => $branch->city,
            'state' => $branch->state,
            'zip' => $branch->zip,
            'create_date' => $branch->create_date,
            'create_by' => $branch->create_by,
        ];
    }

    public function list(): array
    {
        return KioskBranch::with('merchant')
            ->where('status', KioskBranch::STATUS_ACTIVE)
            ->orderByDesc('id')
            ->get()
            ->map(fn (KioskBranch $branch) => $this->present($branch))
            ->all();
    }

    public function listMerchantsForPicker(): array
    {
        return Merchant::orderBy('merchant_name')
            ->get(['id', 'client_id', 'merchant_name'])
            ->map(fn (Merchant $m) => ['id' => $m->id, 'client_id' => $m->client_id, 'name' => $m->merchant_name])
            ->all();
    }

    /**
     * @throws ValidationException
     */
    private function findOrFail(int $id): KioskBranch
    {
        $branch = KioskBranch::where('status', KioskBranch::STATUS_ACTIVE)->find($id);
        if (! $branch) {
            throw ValidationException::withMessages(['id' => ['This kiosk branch was not found.']]);
        }

        return $branch;
    }

    /**
     * @throws ValidationException
     */
    public function create(array $data, User $actor): array
    {
        $errors = [];
        if (! filled($data['merchant_id'] ?? null)) {
            $errors['merchant_id'] = ['Please select a merchant.'];
        }
        if (! filled($data['code'] ?? null)) {
            $errors['code'] = ['Please enter a branch code.'];
        }
        if (! filled($data['name'] ?? null)) {
            $errors['name'] = ['Please enter a branch name.'];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $merchantId = (int) $data['merchant_id'];
        $code = trim((string) $data['code']);

        $taken = KioskBranch::where('status', KioskBranch::STATUS_ACTIVE)
            ->where('client_id', $merchantId)
            ->whereRaw('UPPER(code) = ?', [strtoupper($code)])
            ->exists();
        if ($taken) {
            throw ValidationException::withMessages(['code' => ['Kiosk Branch Code already used.']]);
        }

        $branch = KioskBranch::create([
            'code' => $code,
            'name' => trim((string) $data['name']),
            'address' => $data['address'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'zip' => $data['zip'] ?? null,
            'client_id' => $merchantId,
            'status' => KioskBranch::STATUS_ACTIVE,
            'create_by' => $actor->name ?? $actor->email,
            'create_date' => now(),
        ]);

        ActivityLog::recordAction($actor, 'Kiosk Management', 'created_branch', "Registered kiosk branch {$branch->code} ({$branch->name})", $branch);

        return $this->present($branch->load('merchant'));
    }

    /**
     * @throws ValidationException
     */
    public function delete(int $id, User $actor): void
    {
        $branch = $this->findOrFail($id);

        $branch->update([
            'status' => KioskBranch::STATUS_DELETED,
            'update_by' => $actor->name ?? $actor->email,
            'update_date' => now(),
        ]);

        ActivityLog::recordAction($actor, 'Kiosk Management', 'deleted_branch', "Deleted kiosk branch {$branch->code} ({$branch->name})", $branch);
    }
}
