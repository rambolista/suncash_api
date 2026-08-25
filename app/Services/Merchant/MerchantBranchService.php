<?php

namespace App\Services\Merchant;

use App\Models\Mysuncash\Branch;
use App\Models\Mysuncash\Island;
use App\Models\Mysuncash\Merchant;
use App\Models\Mysuncash\MerchantTerminalUser;
use Illuminate\Validation\ValidationException;

class MerchantBranchService
{
    private function findMerchantOrFail(int $merchantId): Merchant
    {
        $merchant = Merchant::find($merchantId);
        if (! $merchant) {
            throw ValidationException::withMessages(['id' => ['Merchant not found.']]);
        }

        return $merchant;
    }

    private function findBranchOrFail(int $merchantId, int $branchId): Branch
    {
        $branch = Branch::where('client_record_id', $merchantId)
            ->where('id', $branchId)
            ->whereIn('status', [Branch::STATUS_ACTIVE, Branch::STATUS_INACTIVE])
            ->first();

        if (! $branch) {
            throw ValidationException::withMessages(['id' => ['Branch not found.']]);
        }

        return $branch;
    }

    public function listBranches(int $merchantId): array
    {
        return Branch::where('client_record_id', $merchantId)
            ->whereIn('status', [Branch::STATUS_ACTIVE, Branch::STATUS_INACTIVE])
            ->orderBy('branch_code')
            ->get()
            ->map(fn (Branch $branch) => [
                'id' => $branch->id,
                'branch_code' => $branch->branch_code,
                'description' => $branch->description,
                'status' => $branch->status === Branch::STATUS_ACTIVE ? 'active' : 'inactive',
                'address1' => $branch->address1,
                'address2' => $branch->address2,
                'island' => $branch->island,
                'island_location' => $branch->island_location,
                'city' => $branch->city,
                'state' => $branch->state,
            ])
            ->all();
    }

    public function getBranch(int $merchantId, int $branchId): array
    {
        $branch = $this->findBranchOrFail($merchantId, $branchId);

        return $branch->only([
            'id', 'branch_code', 'description', 'address1', 'address2',
            'island', 'island_location', 'city', 'state', 'card_pickup_location',
            'bec_commission', 'wsc_commission', 'btc_commission', 'fc_commission',
            'bec_commission_limit', 'wsc_commission_limit', 'btc_commission_limit', 'fc_commission_limit',
            'ma_commission', 'ma_commission_limit',
        ]);
    }

    private function validateBranchData(array $data): array
    {
        $errors = [];
        foreach (['branch_code', 'description', 'island', 'island_location', 'address1'] as $field) {
            if (! filled($data[$field] ?? null)) {
                $errors[$field] = ['This field is required.'];
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'branch_code' => trim($data['branch_code']),
            'description' => trim($data['description']),
            'island' => trim($data['island']),
            'island_location' => trim($data['island_location']),
            'address1' => trim($data['address1']),
            'address2' => trim($data['address2'] ?? ''),
            'city' => trim($data['city'] ?? ''),
            'state' => trim($data['state'] ?? ''),
            'card_pickup_location' => $data['card_pickup_location'] ?? null,
        ];
    }

    /**
     * @throws ValidationException
     */
    public function createBranch(int $merchantId, array $data, string $actorId): array
    {
        $this->findMerchantOrFail($merchantId);
        $attributes = $this->validateBranchData($data);

        $codeTaken = Branch::where('client_record_id', $merchantId)
            ->where('branch_code', $attributes['branch_code'])
            ->exists();
        if ($codeTaken) {
            throw ValidationException::withMessages(['branch_code' => ['Branch code already in use for this merchant.']]);
        }

        $branch = Branch::create($attributes + [
            'client_record_id' => $merchantId,
            'status' => Branch::STATUS_ACTIVE,
            'created_by' => $actorId,
        ]);

        return $this->getBranch($merchantId, $branch->id);
    }

    /**
     * @throws ValidationException
     */
    public function updateBranch(int $merchantId, int $branchId, array $data, string $actorId): array
    {
        $branch = $this->findBranchOrFail($merchantId, $branchId);
        $attributes = $this->validateBranchData($data);

        $codeTaken = Branch::where('client_record_id', $merchantId)
            ->where('branch_code', $attributes['branch_code'])
            ->where('id', '!=', $branchId)
            ->exists();
        if ($codeTaken) {
            throw ValidationException::withMessages(['branch_code' => ['Branch code already in use for this merchant.']]);
        }

        $branch->update($attributes + ['updated_by' => $actorId]);

        // Keep the denormalized location string on POS users in sync, matching legacy behavior.
        MerchantTerminalUser::where('branch_id', $branchId)->update(['location' => $attributes['description']]);

        return $this->getBranch($merchantId, $branchId);
    }

    /**
     * @throws ValidationException
     */
    public function changeBranchStatus(int $merchantId, int $branchId, string $status): array
    {
        if (! in_array($status, [Branch::STATUS_ACTIVE, Branch::STATUS_INACTIVE, Branch::STATUS_DELETED], true)) {
            throw ValidationException::withMessages(['status' => ['Invalid branch status.']]);
        }

        $branch = Branch::where('client_record_id', $merchantId)->where('id', $branchId)->first();
        if (! $branch) {
            throw ValidationException::withMessages(['id' => ['Branch not found.']]);
        }

        $branch->update(['status' => $status]);

        return ['id' => $branch->id, 'status' => $status];
    }

    public function listIslands(): array
    {
        return Island::where('status', 'A')
            ->with(['cities' => fn ($query) => $query->where('status', 'A')->orderBy('city_name')])
            ->orderBy('name')
            ->get()
            ->map(fn (Island $island) => [
                'id' => $island->id,
                'name' => $island->name,
                'cities' => $island->cities->pluck('city_name')->all(),
            ])
            ->all();
    }
}
