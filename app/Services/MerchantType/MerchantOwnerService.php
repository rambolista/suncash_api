<?php

namespace App\Services\MerchantType;

use App\Models\Mysuncash\Merchant;
use App\Models\Mysuncash\MerchantOwner;
use Illuminate\Validation\ValidationException;

/**
 * Owners/directors declared on a Business's Initial Info screen (mirrors
 * legacy's business_other_info CRUD). Document uploads (government ID, bank
 * statement, resolution) and PEP/watchlist flagging are intentionally not
 * ported — see PR description.
 */
class MerchantOwnerService
{
    private function validate(array $data): void
    {
        $errors = [];

        foreach (['owner_name', 'dob', 'mobile_number', 'position_level', 'id_type', 'id_number', 'expiry_date'] as $field) {
            if (! filled($data[$field] ?? null)) {
                $errors[$field] = ['This field is required.'];
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @throws ValidationException
     */
    public function create(int $merchantId, array $data): MerchantOwner
    {
        if (! Merchant::where('merchant_type_id', Merchant::MERCHANT_TYPE_BUSINESS)->where('id', $merchantId)->exists()) {
            throw ValidationException::withMessages(['merchant_id' => ['Business not found.']]);
        }

        $this->validate($data);

        return MerchantOwner::create([
            'client_record_id' => $merchantId,
            'owner_name' => $data['owner_name'],
            'dob' => $data['dob'],
            'mobile_number' => $data['mobile_number'],
            'id_type' => $data['id_type'],
            'id_number' => $data['id_number'],
            'expiry_date' => $data['expiry_date'],
            's_id_type' => $data['s_id_type'] ?? null,
            's_id_number' => $data['s_id_number'] ?? null,
            's_expiry_date' => $data['s_expiry_date'] ?? null,
            'position_level' => $data['position_level'],
            'signatory_rights' => $data['signatory_rights'] ?? null,
            'created_date' => now(),
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function update(int $merchantId, int $ownerId, array $data): MerchantOwner
    {
        $owner = MerchantOwner::where('client_record_id', $merchantId)->find($ownerId);
        if (! $owner) {
            throw ValidationException::withMessages(['id' => ['Owner not found.']]);
        }

        $this->validate($data);

        $owner->update([
            'owner_name' => $data['owner_name'],
            'dob' => $data['dob'],
            'mobile_number' => $data['mobile_number'],
            'id_type' => $data['id_type'],
            'id_number' => $data['id_number'],
            'expiry_date' => $data['expiry_date'],
            's_id_type' => $data['s_id_type'] ?? null,
            's_id_number' => $data['s_id_number'] ?? null,
            's_expiry_date' => $data['s_expiry_date'] ?? null,
            'position_level' => $data['position_level'],
            'signatory_rights' => $data['signatory_rights'] ?? null,
            'updated_date' => now(),
        ]);

        return $owner->fresh();
    }
}
