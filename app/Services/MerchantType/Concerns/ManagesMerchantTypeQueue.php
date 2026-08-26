<?php

namespace App\Services\MerchantType\Concerns;

use App\Models\Mysuncash\Branch;
use App\Models\Mysuncash\Island;
use App\Models\Mysuncash\Merchant;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Shared pending/approved/rejected queue mechanics for Business Management
 * and Charity Management — both are the same `clients` list/approve/reject
 * flow, filtered by a different `merchant_type_id` (mirrors legacy's
 * get_client_registration_bystatus()/get_charity_registration_bystatus(),
 * approve_merchant()/reject_merchant()).
 */
trait ManagesMerchantTypeQueue
{
    abstract protected function merchantTypeId(): int;

    public function list(): array
    {
        $base = fn () => Merchant::with('billpayApplication')
            ->where('merchant_type_id', $this->merchantTypeId())
            ->whereIn('client_status_id', [-1, 0]);

        $mapRow = function (Merchant $merchant) {
            $island = $merchant->billpayApplication?->island;

            return [
                'id' => $merchant->id,
                'client_id' => $merchant->client_id,
                'user_name' => $merchant->user_name,
                'dba_name' => $merchant->dba_name,
                'legal_name' => $merchant->legal_name,
                'tax_id' => $merchant->tax_id,
                'phone_no' => $merchant->phone_no,
                'reseller_name' => $merchant->reseller_name,
                'suntag_shortcode' => $merchant->suntag_shortcode,
                'island' => is_numeric($island) ? (Island::find((int) $island)?->name ?? $island) : $island,
                'client_status_id' => (int) $merchant->client_status_id,
                'registration_status' => $merchant->registration_status,
                'creation_date' => $merchant->creation_date,
                'modification_date' => $merchant->modification_date,
            ];
        };

        return [
            'pending' => $base()->where('registration_status', 'P')->orderByDesc('id')->get()->map($mapRow)->all(),
            'approved' => $base()->where('registration_status', 'A')->orderByDesc('id')->get()->map($mapRow)->all(),
            'rejected' => $base()->where('registration_status', 'V')->orderByDesc('id')->get()->map($mapRow)->all(),
        ];
    }

    private function findPendingOrFail(int $merchantId): Merchant
    {
        $merchant = Merchant::where('merchant_type_id', $this->merchantTypeId())
            ->where('registration_status', 'P')
            ->find($merchantId);

        if (! $merchant) {
            throw ValidationException::withMessages(['id' => ['Pending registration not found.']]);
        }

        return $merchant;
    }

    /**
     * @throws ValidationException
     */
    public function approve(int $merchantId, string $actorId): Merchant
    {
        $merchant = $this->findPendingOrFail($merchantId);

        $merchant->update([
            'registration_status' => 'A',
            'client_status_id' => -1,
            'user_id_modify' => $actorId,
            'modification_date' => now(),
        ]);

        // Legacy's activate_registered_merchant() always creates one default branch on approval.
        Branch::create([
            'branch_code' => Str::upper(Str::random(8)),
            'description' => "DefaultBranch_{$merchant->id}",
            'client_record_id' => $merchant->id,
            'created_by' => $actorId,
        ]);

        return $merchant->fresh();
    }

    /**
     * @throws ValidationException
     */
    public function reject(int $merchantId, string $actorId): Merchant
    {
        $merchant = $this->findPendingOrFail($merchantId);

        $merchant->update([
            'registration_status' => 'V',
            'client_status_id' => -1,
            'user_id_modify' => $actorId,
            'modification_date' => now(),
        ]);

        return $merchant->fresh();
    }
}
