<?php

namespace App\Services\MerchantType;

use App\Models\Mysuncash\ClientBillpayApplication;
use App\Models\Mysuncash\ClientDocument;
use App\Models\Mysuncash\Merchant;
use App\Services\MerchantType\Concerns\ManagesMerchantTypeQueue;
use Illuminate\Validation\ValidationException;

/**
 * Charity Management — mirrors legacy admin's `charity_online_signup`
 * (list/approve/reject) and `charity_initial_info` (review/edit detail),
 * scoped to `clients.merchant_type_id = 3`. A materially smaller field set
 * than Business: no owners, no compliance screening, no TIN/risk/business
 * size — legacy's charity_initial_info_update() never touches those.
 */
class CharityManagementService
{
    use ManagesMerchantTypeQueue;

    protected function merchantTypeId(): int
    {
        return Merchant::MERCHANT_TYPE_CHARITY;
    }

    /**
     * @throws ValidationException
     */
    public function getInitialInfo(int $merchantId): array
    {
        $merchant = Merchant::where('merchant_type_id', $this->merchantTypeId())->find($merchantId);
        if (! $merchant) {
            throw ValidationException::withMessages(['id' => ['Charity not found.']]);
        }

        $application = ClientBillpayApplication::where('client_id', $merchantId)->first();
        $documents = ClientDocument::where('client_id', $merchantId)->orderBy('id')->get();

        return [
            'merchant' => [
                'id' => $merchant->id,
                'client_id' => $merchant->client_id,
                'dba_name' => $merchant->dba_name,
                'suntag_shortcode' => $merchant->suntag_shortcode,
                'require_second_auth' => (bool) $merchant->require_second_auth,
                'registration_status' => $merchant->registration_status,
                'client_status_id' => (int) $merchant->client_status_id,
            ],
            'application' => $application ? [
                'sole_proprietorship' => $application->sole_proprietorship,
                'business_license_no' => $application->business_license_no,
                'company_address' => $application->company_address,
                'island' => $application->island,
                'country' => $application->country,
                'head_office_telephone_no1' => $application->head_office_telephone_no1,
                'business_email_address' => $application->business_email_address,
                'business_website' => $application->business_website,
                'primary_contact' => $application->primary_contact,
                'p_telephone_no' => $application->p_telephone_no,
                'p_email_address' => $application->p_email_address,
                'secondary_contact' => $application->secondary_contact,
                's_telephone_no' => $application->s_telephone_no,
                's_email_address' => $application->s_email_address,
                'cert_issue_date' => $application->cert_issue_date,
                'purpose' => $application->purpose,
                'activities' => $application->activities,
            ] : null,
            'documents' => $documents->map(fn ($doc) => [
                'id' => $doc->id,
                'file_field' => $doc->file_field,
                'file_type' => $doc->file_type,
                'file_url' => $doc->file_url,
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
            throw ValidationException::withMessages(['id' => ['Charity not found.']]);
        }

        $merchant->update([
            'dba_name' => $data['dba_name'] ?? $merchant->dba_name,
            'suntag_shortcode' => $data['suntag_shortcode'] ?? $merchant->suntag_shortcode,
            'require_second_auth' => ! empty($data['require_second_auth']) ? 1 : 0,
            'user_id_modify' => $actorId,
            'modification_date' => now(),
        ]);

        $applicationAttributes = [
            'sole_proprietorship' => $data['sole_proprietorship'] ?? null,
            'business_license_no' => $data['business_license_no'] ?? null,
            'company_address' => $data['company_address'] ?? null,
            'island' => $data['island'] ?? null,
            'country' => $data['country'] ?? null,
            'head_office_telephone_no1' => $data['head_office_telephone_no1'] ?? null,
            'business_email_address' => $data['business_email_address'] ?? null,
            'business_website' => $data['business_website'] ?? null,
            'primary_contact' => $data['primary_contact'] ?? null,
            'p_telephone_no' => $data['p_telephone_no'] ?? null,
            'p_email_address' => $data['p_email_address'] ?? null,
            'secondary_contact' => $data['secondary_contact'] ?? null,
            's_telephone_no' => $data['s_telephone_no'] ?? null,
            's_email_address' => $data['s_email_address'] ?? null,
            'cert_issue_date' => $data['cert_issue_date'] ?? null,
            'purpose' => $data['purpose'] ?? null,
            'activities' => $data['activities'] ?? null,
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
