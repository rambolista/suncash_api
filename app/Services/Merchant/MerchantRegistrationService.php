<?php

namespace App\Services\Merchant;

use App\Models\Mysuncash\AdminTransaction;
use App\Models\Mysuncash\Biller;
use App\Models\Mysuncash\CharitableInstitution;
use App\Models\Mysuncash\ClientTransaction;
use App\Models\Mysuncash\Merchant;
use App\Models\Mysuncash\MerchantDetail;
use App\Models\Mysuncash\MerchantFeeCommission;
use App\Models\Mysuncash\UserAccount;
use App\Models\Mysuncash\UserKey;
use App\Services\LegacyCredentialCipher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ports the legacy mysuncash-stage "Add Merchant" wizard
 * (admin/application/controllers/administrator.php::client_add and
 * admin/application/models/clients_model.php::client_registration) onto the
 * `mysuncash` connection, via the App\Models\Mysuncash\* Eloquent models —
 * so new registrations land in the same `clients` / `merchant_details` /
 * `merchant_fees_and_commissions` / `user_account` / `user_keys` /
 * `admin_transactions` / `client_transactions` tables the legacy admin,
 * merchant portal, and reporting stack already read from.
 *
 * This class is an orchestrator, not a repository: registering or updating a
 * merchant is one atomic operation spanning up to eight tables, which is a
 * transaction-boundary concern that doesn't belong on any single model. The
 * models own their own schema/relationships; MerchantValidator owns the
 * business rules for what a payload must look like; this service owns
 * stitching persistence together for a validated payload.
 *
 * The merchant logo is uploaded up front via MerchantController::uploadLogo()
 * (mirrors the legacy admin's separate upload_temp_company_image step) and
 * its resulting public URL is passed in as `logo`; the "Mark as Ezpay" tag
 * sets `clients.is_ezpay` directly, matching clients_model::update_ezpay_tag().
 */
class MerchantRegistrationService
{
    public function __construct(private readonly MerchantValidator $validator)
    {
    }

    public function isClientIdAvailable(string $clientId): bool
    {
        return ! Merchant::where('client_id', $clientId)->exists();
    }

    public function isUsernameAvailable(string $username): bool
    {
        return ! UserAccount::where('user_name', $username)->exists();
    }

    public function listMerchants(): array
    {
        return Merchant::query()
            ->with('clientStatus')
            ->orderByDesc('id')
            ->limit(500)
            ->get()
            ->map(function (Merchant $merchant) {
                $entityType = is_numeric($merchant->reseller_type) ? (int) $merchant->reseller_type : null;

                return [
                    'id' => $merchant->id,
                    'client_id' => $merchant->client_id,
                    'legal_name' => $merchant->legal_name,
                    'dba_name' => $merchant->dba_name,
                    'merchant_name' => $merchant->merchant_name,
                    'phone_no' => $merchant->phone_no,
                    'entity_type' => $entityType,
                    'entity_type_label' => Merchant::ENTITY_TYPES[$entityType] ?? null,
                    'registration_status' => $merchant->registration_status,
                    'account_status' => $merchant->clientStatus->status ?? 'active',
                    'creation_date' => $merchant->creation_date,
                ];
            })
            ->all();
    }

    /**
     * @throws ValidationException
     */
    public function getMerchant(int $id): array
    {
        $merchant = Merchant::with(['merchantDetail', 'feeCommissions', 'clientStatus'])->find($id);
        if (! $merchant) {
            throw ValidationException::withMessages(['id' => ['Merchant not found.']]);
        }

        $details = $merchant->merchantDetail;

        $feesById = [];
        foreach ($merchant->feeCommissions as $fee) {
            $feesById[$fee->main_transaction_type_id] = [
                'trans_fee' => (string) $fee->transaction_fee,
                'comms_per_trans' => (string) $fee->commission_per_transaction,
                'charge_to' => $fee->charge_to !== null ? (string) $fee->charge_to : '',
            ];
        }

        return [
            'id' => $merchant->id,
            'merchant_id' => $merchant->client_id,
            'client_id' => $merchant->client_id,
            'username' => $merchant->user_name,
            'exact_legal_name' => $merchant->legal_name,
            'legal_name' => $merchant->legal_name,
            'doing_business_as' => $merchant->merchant_name,
            'dba_name' => $merchant->dba_name,
            'tax_id' => $merchant->tax_id,
            'entity_type' => is_numeric($merchant->reseller_type) ? (int) $merchant->reseller_type : '',
            'contactphone' => $merchant->phone_no,
            'contactfax' => $merchant->fax_no,
            'registration_status' => $merchant->registration_status,
            'account_status' => $merchant->clientStatus->status ?? 'active',
            'client_prefund' => (float) $merchant->client_prefund,
            'ezpay_merchant' => ($merchant->is_ezpay ?? '0') === '1',
            'logo' => $details->logo ?? '',
            'address1' => $details->address1 ?? '',
            'address2' => $details->address2 ?? '',
            'city' => $details->city ?? '',
            'postalcode' => $details->postalcode ?? '',
            'country' => $details->country ?? '',
            'billing_address' => $details->billing_address ?? '',
            'billing_city' => $details->billing_city ?? '',
            'billing_postalcode' => $details->billing_postalcode ?? '',
            'business_license_number' => $details->business_license_number ?? '',
            'contactmobile' => $details->contactmobile ?? '',
            'contactemail' => $details->contactemail ?? '',
            'contactname' => $details->contactname ?? '',
            'payment_mode' => $details->payment_mode ?? 'credittoaccount',
            'bank_name' => $details->bank_name ?? '',
            'bank_branch' => $details->bank_branch ?? '',
            'account_name' => $details->account_name ?? '',
            'account_number' => $details->account_number ?? '',
            'account_type' => $details->account_type ?? 'savingsaccount',
            'routing_number' => $details->routing_number ?? '',
            'revenue_value' => $details->revenue_share ?? '',
            'locations' => $details->locations ?? '',
            'alert_amount' => $details->alert_amount ?? '',
            'via_sms' => (bool) ($details->via_sms ?? false),
            'sms_daily' => (bool) ($details->sms_daily ?? false),
            'sms_weekly' => (bool) ($details->sms_weekly ?? false),
            'sms_monthly' => (bool) ($details->sms_monthly ?? false),
            'sms_primary' => $details->sms_primary ?? '',
            'sms_secondary' => $details->sms_secondary ?? '',
            'via_email' => (bool) ($details->via_email ?? false),
            'email_daily' => (bool) ($details->email_daily ?? false),
            'email_weekly' => (bool) ($details->email_weekly ?? false),
            'email_monthly' => (bool) ($details->email_monthly ?? false),
            'email_primary' => $details->email_primary ?? '',
            'email_secondary' => $details->email_secondary ?? '',
            'via_hardcopy' => (bool) ($details->via_hardcopy ?? false),
            'hardcopy_daily' => (bool) ($details->hardcopy_daily ?? false),
            'hardcopy_weekly' => (bool) ($details->hardcopy_weekly ?? false),
            'hardcopy_monthly' => (bool) ($details->hardcopy_monthly ?? false),
            'hardcopy_address' => $details->hardcopy_address ?? '',
            'alert_sms' => (bool) ($details->alert_sms ?? false),
            'alert_sms_hour' => $details->alert_sms_hour ?? '',
            'alert_sms_recipients' => $details->alert_sms_recipients ?? '',
            'alert_email' => (bool) ($details->alert_email ?? false),
            'alert_email_hour' => $details->alert_email_hour ?? '',
            'alert_email_recipients' => $details->alert_email_recipients ?? '',
            'fees' => $feesById,
        ];
    }

    /**
     * @throws ValidationException
     */
    public function register(array $data, string $createdBy): array
    {
        $this->validator->validateForRegistration(
            $data,
            $this->isClientIdAvailable($data['merchant_id']),
            $this->isUsernameAvailable($data['username'])
        );

        return DB::connection('mysuncash')->transaction(function () use ($data, $createdBy) {
            $entityType = (int) $data['entity_type'];
            $now = now();

            [$encryptedPassword, $userKey] = array_values(LegacyCredentialCipher::encrypt($data['password']));

            $merchant = Merchant::create($this->buildMerchantAttributesForCreate($data, $createdBy, $now, $entityType, $encryptedPassword));

            MerchantDetail::create(array_merge(
                $this->buildMerchantDetailAttributes($data, $now),
                ['client_record_id' => $merchant->id, 'date_created' => $now]
            ));

            $this->syncFeeCommissions($merchant->id, $data['fees'] ?? [], $now, $createdBy);

            $userAccount = $this->createPortalAccount($merchant->id, $data, $encryptedPassword, $createdBy, $now);
            UserKey::create([
                'user_id' => $userAccount->id,
                'key' => $userKey,
                'channel' => $entityType === 6 ? 'charity' : 'business',
            ]);

            $this->logRegistration($merchant->id, $data['merchant_id'], $now);
            $this->registerShortCode($merchant->id, $entityType, $data, $now);

            return ['client_record_id' => $merchant->id, 'client_id' => $data['merchant_id']];
        });
    }

    /**
     * @throws ValidationException
     */
    public function update(int $id, array $data, string $updatedBy): array
    {
        $merchant = Merchant::find($id);
        if (! $merchant) {
            throw ValidationException::withMessages(['id' => ['Merchant not found.']]);
        }

        $this->validator->validateForUpdate($data);

        return DB::connection('mysuncash')->transaction(function () use ($merchant, $id, $data, $updatedBy) {
            $now = now();

            $merchant->update($this->buildMerchantAttributesForUpdate($data, $updatedBy, $now));

            if (filled($data['password'] ?? null)) {
                $this->updatePortalPassword($id, $data['password'], $updatedBy, $now);
            }

            $detailAttributes = $this->buildMerchantDetailAttributes($data, $now);
            if (! empty($data['clear_logo'])) {
                $detailAttributes['logo'] = null;
            }

            $existingDetail = MerchantDetail::where('client_record_id', $id)->first();
            if ($existingDetail) {
                $existingDetail->update($detailAttributes);
            } else {
                MerchantDetail::create(array_merge($detailAttributes, [
                    'client_record_id' => $id,
                    'date_created' => $now,
                ]));
            }

            $this->syncFeeCommissions($id, $data['fees'] ?? [], $now, $updatedBy);

            return ['client_record_id' => $id, 'client_id' => $merchant->client_id];
        });
    }

    /** clients-table attributes for a brand-new registration. */
    private function buildMerchantAttributesForCreate(array $data, string $createdBy, Carbon $now, int $entityType, string $encryptedPassword): array
    {
        $attributes = [
            'client_id' => $data['merchant_id'],
            'user_name' => $data['username'],
            'password' => $encryptedPassword,
            'client_prefund' => 0,
            'client_settlement' => 0,
            'client_status_id' => 0,
            'user_id_create' => $createdBy,
            'user_id_modify' => $createdBy,
            'creation_date' => $now,
            'modification_date' => $now,
            'legal_name' => $data['exact_legal_name'],
            'dba_name' => $data['dba_name'] ?? '',
            'tax_id' => $data['tax_id'] ?? '',
            'merchant_name' => $data['doing_business_as'] ?? '',
            'phone_no' => $data['contactphone'] ?? '',
            'reseller_name' => '',
            'reseller_type' => (string) $entityType,
            'merchant_key' => LegacyCredentialCipher::generateMerchantKey(),
            'fax_no' => $data['contactfax'] ?? '',
            'is_ezpay' => ! empty($data['ezpay_merchant']) ? '1' : '0',
        ];

        if (in_array($entityType, [5, 6], true)) {
            $attributes['merchant_type_id'] = $entityType === 5 ? 1 : 3;
            $attributes['registration_status'] = 'A';
            $attributes['suntag_shortcode'] = $data['merchant_id'];
        }

        return $attributes;
    }

    /** clients-table attributes for editing an existing merchant (identity fields only; see updatePortalPassword() for credentials). */
    private function buildMerchantAttributesForUpdate(array $data, string $updatedBy, Carbon $now): array
    {
        $attributes = [
            'legal_name' => $data['exact_legal_name'],
            'dba_name' => $data['dba_name'] ?? '',
            'tax_id' => $data['tax_id'] ?? '',
            'merchant_name' => $data['doing_business_as'] ?? '',
            'phone_no' => $data['contactphone'] ?? '',
            'fax_no' => $data['contactfax'] ?? '',
            'is_ezpay' => ! empty($data['ezpay_merchant']) ? '1' : '0',
            'user_id_modify' => $updatedBy,
            'modification_date' => $now,
        ];

        if (filled($data['entity_type'] ?? null)) {
            $attributes['reseller_type'] = (string) (int) $data['entity_type'];
        }

        if (filled($data['password'] ?? null)) {
            [$attributes['password']] = array_values(LegacyCredentialCipher::encrypt($data['password']));
        }

        return $attributes;
    }

    private function updatePortalPassword(int $merchantId, string $password, string $updatedBy, Carbon $now): void
    {
        [$encryptedPassword] = array_values(LegacyCredentialCipher::encrypt($password));

        UserAccount::where('user_reference', $merchantId)
            ->where('user_type_id', 1)
            ->update([
                'password' => $encryptedPassword,
                'user_id_modified' => $updatedBy,
                'modification_date' => $now,
            ]);
    }

    /**
     * merchant_details attributes shared by register() and update(). Delivery
     * and alert sub-fields are always written explicitly (rather than only
     * when their channel is enabled) so that turning a channel off during an
     * edit correctly clears its previously-saved values.
     */
    private function buildMerchantDetailAttributes(array $data, Carbon $now): array
    {
        return [
            'billing_address' => $data['billing_address'] ?? null,
            'billing_city' => $data['billing_city'] ?? null,
            'billing_postalcode' => $data['billing_postalcode'] ?? null,
            'business_license_number' => $data['business_license_number'] ?? null,
            'payment_mode' => $data['payment_mode'] ?? null,
            'address1' => $data['address1'] ?? null,
            'address2' => $data['address2'] ?? null,
            'city' => $data['city'] ?? null,
            'postalcode' => $data['postalcode'] ?? null,
            'contactmobile' => $data['contactmobile'] ?? null,
            'contactemail' => $data['contactemail'] ?? null,
            'contactname' => $data['contactname'] ?? null,
            'bank_name' => $data['bank_name'] ?? null,
            'bank_branch' => $data['bank_branch'] ?? null,
            'account_name' => $data['account_name'] ?? null,
            'account_number' => $data['account_number'] ?? null,
            'account_type' => $data['account_type'] ?? null,
            'locations' => $data['locations'] ?? null,
            'routing_number' => $data['routing_number'] ?? null,
            'country' => $data['country'] ?? null,
            'alert_amount' => filled($data['alert_amount'] ?? null) ? $data['alert_amount'] : null,
            'revenue_share' => $data['revenue_value'] ?? null,
            'logo' => filled($data['logo'] ?? null) ? $data['logo'] : null,
            'modification_date' => $now,
            'via_sms' => ! empty($data['via_sms']) ? 1 : null,
            'sms_daily' => ! empty($data['sms_daily']) ? 1 : null,
            'sms_weekly' => ! empty($data['sms_weekly']) ? 1 : null,
            'sms_monthly' => ! empty($data['sms_monthly']) ? 1 : null,
            'sms_primary' => ! empty($data['via_sms']) ? ($data['sms_primary'] ?? null) : null,
            'sms_secondary' => ! empty($data['via_sms']) ? ($data['sms_secondary'] ?? null) : null,
            'via_email' => ! empty($data['via_email']) ? 1 : null,
            'email_daily' => ! empty($data['email_daily']) ? 1 : null,
            'email_weekly' => ! empty($data['email_weekly']) ? 1 : null,
            'email_monthly' => ! empty($data['email_monthly']) ? 1 : null,
            'email_primary' => ! empty($data['via_email']) ? ($data['email_primary'] ?? null) : null,
            'email_secondary' => ! empty($data['via_email']) ? ($data['email_secondary'] ?? null) : null,
            'via_hardcopy' => ! empty($data['via_hardcopy']) ? 1 : null,
            'hardcopy_daily' => ! empty($data['hardcopy_daily']) ? 1 : null,
            'hardcopy_weekly' => ! empty($data['hardcopy_weekly']) ? 1 : null,
            'hardcopy_monthly' => ! empty($data['hardcopy_monthly']) ? 1 : null,
            'hardcopy_address' => ! empty($data['via_hardcopy']) ? ($data['hardcopy_address'] ?? null) : null,
            'alert_sms' => ! empty($data['alert_sms']) ? 1 : null,
            'alert_sms_hour' => ! empty($data['alert_sms']) ? ($data['alert_sms_hour'] ?? null) : null,
            'alert_sms_recipients' => ! empty($data['alert_sms']) ? ($data['alert_sms_recipients'] ?? null) : null,
            'alert_email' => ! empty($data['alert_email']) ? 1 : null,
            'alert_email_hour' => ! empty($data['alert_email']) ? ($data['alert_email_hour'] ?? null) : null,
            'alert_email_recipients' => ! empty($data['alert_email']) ? ($data['alert_email_recipients'] ?? null) : null,
        ];
    }

    /** Replaces a merchant's fee schedule wholesale — simplest way to keep it in sync with whatever rows the form submitted. */
    private function syncFeeCommissions(int $merchantId, array $fees, Carbon $now, string $actorId): void
    {
        MerchantFeeCommission::where('client_record_id', $merchantId)->delete();

        foreach ($fees as $typeId => $fee) {
            $typeId = (int) $typeId;
            $transFee = $fee['trans_fee'] ?? null;

            if (! isset(Merchant::MAIN_TRANSACTION_TYPES[$typeId]) || ! is_numeric($transFee)) {
                continue;
            }

            MerchantFeeCommission::create([
                'client_record_id' => $merchantId,
                'main_transaction_type_id' => $typeId,
                'transaction_fee' => $transFee,
                'commission_per_transaction' => is_numeric($fee['comms_per_trans'] ?? null) ? $fee['comms_per_trans'] : 0,
                'charge_to' => $this->chargeToFor($typeId, $fee),
                'date_created' => $now,
                'created_by' => $actorId,
            ]);
        }
    }

    /** Mirrors $no_charge_to handling in administrator.php::check_fees(). */
    private function chargeToFor(int $typeId, array $fee): ?int
    {
        if (in_array($typeId, MerchantValidator::NO_CHARGE_TO_TYPES, true)) {
            return null;
        }

        return is_numeric($fee['charge_to'] ?? null) ? (int) $fee['charge_to'] : null;
    }

    /** Creates the merchant's login for the legacy Merchant portal (mirrors accounts_model::register_client_user). */
    private function createPortalAccount(int $merchantId, array $data, string $encryptedPassword, string $createdBy, Carbon $now): UserAccount
    {
        return UserAccount::create([
            'user_type_id' => 1,
            'user_reference' => $merchantId,
            'user_name' => $data['username'],
            'password' => $encryptedPassword,
            'user_status_id' => 0,
            'user_id_create' => $createdBy,
            'user_id_modified' => $createdBy,
            'pw_expiration' => $now->copy()->addDays(90)->toDateString(),
            'creation_date' => $now,
            'modification_date' => $now,
            'email_address' => $data['contactemail'],
            'mobile_number' => $data['contactmobile'],
        ]);
    }

    /** Writes the admin + merchant-facing audit log entries a fresh registration always gets. */
    private function logRegistration(int $merchantId, string $clientId, Carbon $now): void
    {
        $adminTransaction = AdminTransaction::create([
            'client_record_id' => $merchantId,
            'trans_type_id' => 2,
            'amount' => 0,
            'description' => 'registered client account ' . $clientId,
            'timestamp' => $now->toDateTimeString(),
            'admin_user_id' => 0,
        ]);

        ClientTransaction::create([
            'client_record_id' => $merchantId,
            'user_type_id' => 0,
            'ref_trans_id' => (string) $adminTransaction->id,
            'trans_type_id' => 2,
            'amount' => 0,
            'description' => 'Your account has been registered',
            'timestamp' => $now,
            'running_balance' => 0,
            'available_balance' => 0,
            'onhold_balance' => 0,
        ]);
    }

    /** Billers and Charitable Institutions get an extra short-code record (mirrors tools_model::save_short_code). */
    private function registerShortCode(int $merchantId, int $entityType, array $data, Carbon $now): void
    {
        $shortCode = trim((string) ($data['short_code'] ?? ''));
        if ($shortCode === '') {
            return;
        }

        $name = $data['doing_business_as'] ?? $data['exact_legal_name'];

        if ($entityType === 3) {
            Biller::create([
                'biller_code' => $shortCode,
                'biller_name' => $name,
                'client_record_id' => $merchantId,
                'fee_amount' => 0,
                'date_created' => $now,
            ]);
        } elseif ($entityType === 4) {
            CharitableInstitution::create([
                'charity_code' => $shortCode,
                'charity_name' => $name,
                'client_record_id' => $merchantId,
                'date_created' => $now,
            ]);
        }
    }
}
