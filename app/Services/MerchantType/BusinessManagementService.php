<?php

namespace App\Services\MerchantType;

use App\Models\Mysuncash\ClientBillpayApplication;
use App\Models\Mysuncash\Merchant;
use App\Services\MerchantType\Concerns\ManagesMerchantTypeQueue;
use Illuminate\Validation\ValidationException;

/**
 * Business Management — mirrors legacy admin's `merchant_online_signup`
 * (list/approve/reject) and `client_initial_info` (review/edit detail),
 * scoped to `clients.merchant_type_id = 1`. The AML/compliance screening
 * block legacy shows here (a live Comply Advantage API call) is
 * intentionally not ported — see PR description.
 */
class BusinessManagementService
{
    use ManagesMerchantTypeQueue;

    protected function merchantTypeId(): int
    {
        return Merchant::MERCHANT_TYPE_BUSINESS;
    }

    /**
     * @throws ValidationException
     */
    public function getInitialInfo(int $merchantId): array
    {
        $merchant = Merchant::where('merchant_type_id', $this->merchantTypeId())
            ->with(['owners' => fn ($q) => $q->orderBy('id')])
            ->find($merchantId);

        if (! $merchant) {
            throw ValidationException::withMessages(['id' => ['Business not found.']]);
        }

        $application = ClientBillpayApplication::where('client_id', $merchantId)->first();

        return [
            'merchant' => [
                'id' => $merchant->id,
                'client_id' => $merchant->client_id,
                'dba_name' => $merchant->dba_name,
                'legal_name' => $merchant->legal_name,
                'trade_name' => $merchant->trade_name,
                'suntag_shortcode' => $merchant->suntag_shortcode,
                'risk_rating' => $merchant->risk_rating,
                'business_size' => $merchant->business_size,
                'require_second_auth' => (bool) $merchant->require_second_auth,
                'registration_status' => $merchant->registration_status,
                'client_status_id' => (int) $merchant->client_status_id,
            ],
            'application' => $application ? [
                'sole_proprietorship' => $application->sole_proprietorship,
                'name_of_parent_company' => $application->name_of_parent_company,
                'business_license_no' => $application->business_license_no,
                'business_shortcode' => $application->business_shortcode,
                'company_address' => $application->company_address,
                'island' => $application->island,
                'country' => $application->country,
                'head_office_telephone_no1' => $application->head_office_telephone_no1,
                'head_office_telephone_no2' => $application->head_office_telephone_no2,
                'business_email_address' => $application->business_email_address,
                'business_website' => $application->business_website,
                'primary_contact' => $application->primary_contact,
                'p_telephone_no' => $application->p_telephone_no,
                'p_email_address' => $application->p_email_address,
                'secondary_contact' => $application->secondary_contact,
                's_telephone_no' => $application->s_telephone_no,
                's_email_address' => $application->s_email_address,
                'name_of_primary_guarantor' => $application->name_of_primary_guarantor,
                'name_of_secondary_guarantor' => $application->name_of_secondary_guarantor,
                'service_categories' => array_filter(explode(',', (string) $application->service_categories)),
                'tin' => $application->tin,
                'tin_expiry' => $application->tin_expiry,
                'sales_representative' => $application->sales_representative,
                'assets_description' => $application->assets_description,
                'description' => $application->description,
                'monthly_amt_of_payments' => $application->monthly_amt_of_payments,
                'monthly_frequency_of_withdrawals' => $application->monthly_frequency_of_withdrawals,
            ] : null,
            'owners' => $merchant->owners->map(fn ($owner) => [
                'id' => $owner->id,
                'owner_name' => $owner->owner_name,
                'dob' => $owner->dob,
                'mobile_number' => $owner->mobile_number,
                'id_type' => $owner->id_type,
                'id_number' => $owner->id_number,
                'expiry_date' => $owner->expiry_date,
                's_id_type' => $owner->s_id_type,
                's_id_number' => $owner->s_id_number,
                's_expiry_date' => $owner->s_expiry_date,
                'position_level' => $owner->position_level,
                'signatory_rights' => $owner->signatory_rights,
            ])->all(),
        ];
    }

    /**
     * @throws ValidationException
     */
    public function updateInitialInfo(int $merchantId, array $data, string $actorId): array
    {
        $merchant = Merchant::where('merchant_type_id', $this->merchantTypeId())->find($merchantId);
        if (! $merchant) {
            throw ValidationException::withMessages(['id' => ['Business not found.']]);
        }

        $merchant->update([
            'dba_name' => $data['dba_name'] ?? $merchant->dba_name,
            'trade_name' => $data['trade_name'] ?? null,
            'suntag_shortcode' => $data['suntag_shortcode'] ?? $merchant->suntag_shortcode,
            'risk_rating' => $data['risk_rating'] ?? $merchant->risk_rating,
            'business_size' => $data['business_size'] ?? null,
            'require_second_auth' => ! empty($data['require_second_auth']) ? 1 : 0,
            'user_id_modify' => $actorId,
            'modification_date' => now(),
        ]);

        $applicationAttributes = [
            'sole_proprietorship' => $data['sole_proprietorship'] ?? null,
            'name_of_parent_company' => $data['name_of_parent_company'] ?? null,
            'business_license_no' => $data['business_license_no'] ?? null,
            'company_address' => $data['company_address'] ?? null,
            'island' => $data['island'] ?? null,
            'country' => $data['country'] ?? null,
            'head_office_telephone_no1' => $data['head_office_telephone_no1'] ?? null,
            'head_office_telephone_no2' => $data['head_office_telephone_no2'] ?? null,
            'business_email_address' => $data['business_email_address'] ?? null,
            'business_website' => $data['business_website'] ?? null,
            'primary_contact' => $data['primary_contact'] ?? null,
            'p_telephone_no' => $data['p_telephone_no'] ?? null,
            'p_email_address' => $data['p_email_address'] ?? null,
            'secondary_contact' => $data['secondary_contact'] ?? null,
            's_telephone_no' => $data['s_telephone_no'] ?? null,
            's_email_address' => $data['s_email_address'] ?? null,
            'name_of_primary_guarantor' => $data['name_of_primary_guarantor'] ?? null,
            'name_of_secondary_guarantor' => $data['name_of_secondary_guarantor'] ?? null,
            'service_categories' => implode(',', $data['service_categories'] ?? []),
            'tin' => $data['tin'] ?? null,
            'tin_expiry' => $data['tin_expiry'] ?? null,
            'sales_representative' => $data['sales_representative'] ?? null,
            'assets_description' => $data['assets_description'] ?? null,
            'description' => $data['description'] ?? null,
            'monthly_amt_of_payments' => $data['monthly_amt_of_payments'] ?? null,
            'monthly_frequency_of_withdrawals' => $data['monthly_frequency_of_withdrawals'] ?? null,
            'modification_date' => now(),
        ];

        $application = ClientBillpayApplication::where('client_id', $merchantId)->first();
        if ($application) {
            $application->update($applicationAttributes);
        } else {
            ClientBillpayApplication::create($applicationAttributes + ['client_id' => $merchantId]);
        }

        return $this->getInitialInfo($merchantId);
    }
}
