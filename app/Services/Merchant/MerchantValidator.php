<?php

namespace App\Services\Merchant;

use App\Models\Mysuncash\Merchant;
use Illuminate\Validation\ValidationException;

/**
 * All validation rules for the legacy client_add / merchant-edit flow, kept
 * separate from MerchantRegistrationService so the service only orchestrates
 * persistence and this class only decides whether a payload is acceptable.
 */
class MerchantValidator
{
    /** Transaction types that must NOT carry a charge_to value (mirrors $no_charge_to in administrator.php). */
    public const NO_CHARGE_TO_TYPES = [8, 9, 10, 11, 12, 13, 14, 15, 16, 20, 21, 23, 24, 25, 26, 28, 29];

    /** Bahamas uses a single NANP area code (242) for the whole country. */
    private const BAHAMAS_PHONE_PATTERN = '/^(\+?1[-.\s]?)?\(?242\)?[-.\s]?\d{3}[-.\s]?\d{4}$/';

    /** Non-negative amount, up to 2 decimal places. */
    private const AMOUNT_PATTERN = '/^\d+(\.\d{1,2})?$/';

    /**
     * @throws ValidationException
     */
    public function validateForRegistration(array $data, bool $clientIdAvailable, bool $usernameAvailable): void
    {
        $required = [
            'merchant_id', 'username', 'password', 'exact_legal_name', 'entity_type',
            'address1', 'city', 'contactmobile', 'contactemail', 'contactname',
            'payment_mode', 'bank_name', 'bank_branch', 'account_name', 'account_number',
        ];

        $this->requireFilled($data, $required);

        if (! array_key_exists((int) $data['entity_type'], Merchant::ENTITY_TYPES)) {
            throw ValidationException::withMessages(['entity_type' => ['Invalid entity type.']]);
        }

        $this->validatePasswordStrength($data['password']);

        if (! $clientIdAvailable) {
            throw ValidationException::withMessages(['merchant_id' => ['Merchant ID already exists.']]);
        }

        if (! $usernameAvailable) {
            throw ValidationException::withMessages(['username' => ['Username is already taken.']]);
        }

        $this->validateContactFormat($data);
        $this->validateAmounts($data);
        $this->validateDeliveryAndAlerts($data, requireMethod: true);
    }

    /**
     * Editing is done from free-navigation tabs, not a linear wizard — an
     * admin may open one tab, fix one field, and save without having visited
     * (or completed) the others. Legacy records also commonly have blank
     * address/settlement/delivery data from before this admin existed. So
     * unlike registration, an update only requires the merchant's core
     * identity (legal name) and validates *format* on whatever fields are
     * present, rather than requiring every field to be filled.
     *
     * @throws ValidationException
     */
    public function validateForUpdate(array $data): void
    {
        $this->requireFilled($data, ['exact_legal_name']);

        if (filled($data['entity_type'] ?? null) && ! array_key_exists((int) $data['entity_type'], Merchant::ENTITY_TYPES)) {
            throw ValidationException::withMessages(['entity_type' => ['Invalid entity type.']]);
        }

        if (filled($data['password'] ?? null)) {
            $this->validatePasswordStrength($data['password']);
        }

        $this->validateContactFormat($data);
        $this->validateAmounts($data);
        $this->validateDeliveryAndAlerts($data, requireMethod: false);
    }

    /**
     * @throws ValidationException
     */
    private function requireFilled(array $data, array $fields): void
    {
        $errors = [];

        foreach ($fields as $field) {
            if (! filled($data[$field] ?? null)) {
                $errors[$field] = ['This field is required.'];
            }
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @throws ValidationException
     */
    private function validatePasswordStrength(string $password): void
    {
        if (preg_match('/((?=.*\d)(?=.*[a-zA-Z]).{6,20})/', $password) !== 1) {
            throw ValidationException::withMessages(['password' => ['A valid password must contain at least 1 letter, 1 number and must be within 6 to 20 characters long.']]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function validateAmounts(array $data): void
    {
        if (filled($data['revenue_value'] ?? null) && preg_match(self::AMOUNT_PATTERN, (string) $data['revenue_value']) !== 1) {
            throw ValidationException::withMessages(['revenue_value' => ['Enter a valid amount (e.g. 2.5), up to 2 decimal places.']]);
        }

        foreach ($data['fees'] ?? [] as $typeId => $fee) {
            if (filled($fee['trans_fee'] ?? null) && preg_match(self::AMOUNT_PATTERN, (string) $fee['trans_fee']) !== 1) {
                throw ValidationException::withMessages(["fee_trans_fee_{$typeId}" => ['Enter a valid amount.']]);
            }

            if (filled($fee['comms_per_trans'] ?? null) && preg_match(self::AMOUNT_PATTERN, (string) $fee['comms_per_trans']) !== 1) {
                throw ValidationException::withMessages(["fee_comms_per_trans_{$typeId}" => ['Enter a valid amount.']]);
            }
        }
    }

    /**
     * @throws ValidationException
     */
    private function validateContactFormat(array $data): void
    {
        if (filled($data['contactemail'] ?? null) && ! filter_var($data['contactemail'], FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['contactemail' => ['Enter a valid e-mail address.']]);
        }

        if (filled($data['contactmobile'] ?? null) && preg_match(self::BAHAMAS_PHONE_PATTERN, (string) $data['contactmobile']) !== 1) {
            throw ValidationException::withMessages(['contactmobile' => ['Enter a valid Bahamas mobile number, e.g. 242-123-4567.']]);
        }

        if (filled($data['contactphone'] ?? null) && preg_match(self::BAHAMAS_PHONE_PATTERN, (string) $data['contactphone']) !== 1) {
            throw ValidationException::withMessages(['contactphone' => ['Enter a valid Bahamas phone number, e.g. 242-123-4567.']]);
        }
    }

    /**
     * @throws ValidationException
     */
    private function validateDeliveryAndAlerts(array $data, bool $requireMethod): void
    {
        $hasDelivery = ! empty($data['via_sms']) || ! empty($data['via_email']) || ! empty($data['via_hardcopy']);
        if ($requireMethod && ! $hasDelivery) {
            throw ValidationException::withMessages(['via_sms' => ['Please select at least one report delivery method.']]);
        }

        if (! empty($data['via_sms']) && ! (! empty($data['sms_daily']) || ! empty($data['sms_weekly']) || ! empty($data['sms_monthly']))) {
            throw ValidationException::withMessages(['sms_daily' => ['Please select the frequency of SMS report delivery.']]);
        }

        if (! empty($data['via_sms']) && ! filled($data['sms_primary'] ?? null)) {
            throw ValidationException::withMessages(['sms_primary' => ['Please specify a primary recipient of SMS reports.']]);
        }

        if (! empty($data['via_email']) && ! (! empty($data['email_daily']) || ! empty($data['email_weekly']) || ! empty($data['email_monthly']))) {
            throw ValidationException::withMessages(['email_daily' => ['Please select the frequency of e-mail report delivery.']]);
        }

        if (! empty($data['via_email']) && ! filled($data['email_primary'] ?? null)) {
            throw ValidationException::withMessages(['email_primary' => ['Please specify a primary recipient of e-mail reports.']]);
        }

        if (! empty($data['via_hardcopy']) && ! (! empty($data['hardcopy_daily']) || ! empty($data['hardcopy_weekly']) || ! empty($data['hardcopy_monthly']))) {
            throw ValidationException::withMessages(['hardcopy_daily' => ['Please select the frequency of hardcopy report delivery.']]);
        }

        if (! empty($data['via_hardcopy']) && ! filled($data['hardcopy_address'] ?? null)) {
            throw ValidationException::withMessages(['hardcopy_address' => ['Please specify the address of hardcopy delivery.']]);
        }

        if ((! empty($data['alert_sms']) || ! empty($data['alert_email'])) && ! filled($data['alert_amount'] ?? null)) {
            throw ValidationException::withMessages(['alert_amount' => ['Please specify the lowest amount to reach before sending alerts.']]);
        }

        if (! empty($data['alert_sms']) && ! filled($data['alert_sms_hour'] ?? null)) {
            throw ValidationException::withMessages(['alert_sms_hour' => ['Please specify number of hours for SMS alert frequency.']]);
        }

        if (! empty($data['alert_sms']) && ! filled($data['alert_sms_recipients'] ?? null)) {
            throw ValidationException::withMessages(['alert_sms_recipients' => ['Please specify mobile number/s for SMS alerts.']]);
        }

        if (! empty($data['alert_email']) && ! filled($data['alert_email_hour'] ?? null)) {
            throw ValidationException::withMessages(['alert_email_hour' => ['Please specify number of hours for e-mail alert frequency.']]);
        }

        if (! empty($data['alert_email']) && ! filled($data['alert_email_recipients'] ?? null)) {
            throw ValidationException::withMessages(['alert_email_recipients' => ['Please specify e-mail address for e-mail alerts.']]);
        }
    }
}
