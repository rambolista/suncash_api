<?php

namespace App\Services\Kiosk;

use App\Models\ActivityLog;
use App\Models\Mysuncash\KioskThirdPartyUser;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * "Kiosk Management > Add Partner / Settlement / Commission" (legacy
 * `administrator::kiosk_branch_partners()` / `kiosk_tp_user()` /
 * `validateKioskTpUserParameter()`). A partner is one record with two
 * independent, optional payout profiles — Settlement and Commission — not
 * two separate features; `is_stl`/`is_comm` just toggle which `stl_*`/
 * `comm_*` columns apply. No settlement/commission calculation happens here,
 * matching legacy (see class docblock on `KioskThirdPartyUser`).
 *
 * Edit intentionally allows leaving bank account fields blank to keep the
 * existing value (mirrors legacy's masked-value re-submit guard), so a
 * partial payload never blanks out a previously saved payout profile.
 */
class KioskPartnerService
{
    private function present(KioskThirdPartyUser $partner): array
    {
        $paymentMethod = match (true) {
            $partner->is_stl === '1' && $partner->is_comm === '1' => 'Settlement / Commission',
            $partner->is_comm === '1' => 'Commission',
            $partner->is_stl === '1' => 'Settlement',
            default => 'NONE',
        };

        return [
            'id' => $partner->id,
            'kiosk_branch_id' => $partner->kiosk_branch_id,
            'first_name' => $partner->first_name,
            'middle_name' => $partner->middle_name,
            'last_name' => $partner->last_name,
            'email' => $partner->email,
            'mobile' => $partner->mobile,
            'address' => $partner->address,
            'payment_method' => $paymentMethod,
            'is_settlement' => $partner->is_stl === '1',
            'stl_frequency' => $partner->stl_frequency,
            'stl_type' => $partner->stl_type,
            'stl_suncash' => $partner->stl_type === 'suncash_wallet' ? $partner->stl_suncash : null,
            'stl_business_id' => $partner->stl_business_id,
            'stl_bank_type' => $partner->stl_bank_type,
            'stl_bank_id' => $partner->stl_bank_id,
            'stl_bank_branch_id' => $partner->stl_bank_branch_id,
            'stl_bank_acct_name' => $partner->stl_bank_acct_name,
            'stl_bank_acct_no_masked' => $this->mask($partner->stl_bank_acct_no),
            'is_commission' => $partner->is_comm === '1',
            'comm_frequency' => $partner->comm_frequency,
            'comm_type' => $partner->comm_type,
            'comm_suncash' => $partner->comm_type === 'suncash_wallet' ? $partner->comm_suncash : null,
            'comm_business_id' => $partner->comm_business_id,
            'comm_bank_type' => $partner->comm_bank_type,
            'comm_bank_id' => $partner->comm_bank_id,
            'comm_bank_branch_id' => $partner->comm_bank_branch_id,
            'comm_bank_acct_name' => $partner->comm_bank_acct_name,
            'comm_bank_acct_no_masked' => $this->mask($partner->comm_bank_acct_no),
            'updated_by' => $partner->updated_by,
            'updated_date' => $partner->updated_date,
            'created_date' => $partner->created_date,
        ];
    }

    /** Matches legacy's edit-form prefill mask exactly (`kiosk_partner_form`, "X" padding) — the `update()` unchanged-value guard below depends on this exact character. */
    private function mask(?string $value): ?string
    {
        if (! $value || $value === '-1') {
            return null;
        }

        $len = strlen($value);

        return $len < 4 ? $value : str_repeat('X', $len - 4).substr($value, -4);
    }

    public function list(int $branchId): array
    {
        return KioskThirdPartyUser::where('kiosk_branch_id', $branchId)
            ->where('user_type', KioskThirdPartyUser::USER_TYPE_PARTNER)
            ->where('status', KioskThirdPartyUser::STATUS_ACTIVE)
            ->orderByDesc('id')
            ->get()
            ->map(fn (KioskThirdPartyUser $partner) => $this->present($partner))
            ->all();
    }

    /**
     * @throws ValidationException
     */
    private function findOrFail(int $id): KioskThirdPartyUser
    {
        $partner = KioskThirdPartyUser::where('status', KioskThirdPartyUser::STATUS_ACTIVE)->find($id);
        if (! $partner) {
            throw ValidationException::withMessages(['id' => ['This partner was not found.']]);
        }

        return $partner;
    }

    private function validatePayoutSection(array $data, string $prefix, string $label): array
    {
        $errors = [];
        if (! filled($data[$prefix.'_frequency'] ?? null)) {
            $errors[$prefix.'_frequency'] = ["Please select a {$label} report frequency."];
        }

        $type = $data[$prefix.'_type'] ?? '';
        if (! in_array($type, ['business_account', 'suncash_wallet', 'bank_deposit'], true)) {
            $errors[$prefix.'_type'] = ["Please select a {$label} option."];

            return $errors;
        }

        if ($type === 'business_account' && ! filled($data[$prefix.'_business_id'] ?? null)) {
            $errors[$prefix.'_business_id'] = ['Please select a merchant.'];
        }
        if ($type === 'suncash_wallet' && ! filled($data[$prefix.'_suncash'] ?? null)) {
            $errors[$prefix.'_suncash'] = ['Please enter a suncash account.'];
        }
        if ($type === 'bank_deposit') {
            foreach (['bank_type', 'bank_id', 'bank_branch_id', 'bank_acct_name'] as $field) {
                if (! filled($data[$prefix.'_'.$field] ?? null)) {
                    $errors[$prefix.'_'.$field] = ["Please check your {$label} bank details."];
                }
            }
        }

        return $errors;
    }

    private function validate(array $data): array
    {
        $errors = [];
        foreach (['first_name' => 'first name', 'last_name' => 'last name', 'email' => 'email address', 'mobile' => 'mobile number', 'address' => 'address'] as $field => $label) {
            if (! filled($data[$field] ?? null)) {
                $errors[$field] = ["Please enter a {$label}."];
            }
        }

        if (($data['is_settlement'] ?? false)) {
            $errors = array_merge($errors, $this->validatePayoutSection($data, 'stl', 'settlement'));
        }
        if (($data['is_commission'] ?? false)) {
            $errors = array_merge($errors, $this->validatePayoutSection($data, 'comm', 'commission'));
        }

        return $errors;
    }

    private function payoutSectionFields(array $data, string $prefix, bool $enabled, ?KioskThirdPartyUser $existing = null): array
    {
        if (! $enabled) {
            return [$prefix === 'stl' ? 'is_stl' : 'is_comm' => '0'];
        }

        $type = $data[$prefix.'_type'];
        $bankAccountNumberField = $prefix.'_bank_acct_no';
        $bankAccountNumber = $data[$bankAccountNumberField] ?? null;
        // A masked value (contains X/*) means "unchanged" — keep the existing stored value.
        if ($bankAccountNumber !== null && preg_match('/[Xx*]/', (string) $bankAccountNumber)) {
            $bankAccountNumber = $existing?->{$bankAccountNumberField};
        }

        return [
            ($prefix === 'stl' ? 'is_stl' : 'is_comm') => '1',
            $prefix.'_frequency' => $data[$prefix.'_frequency'],
            $prefix.'_type' => $type,
            $prefix.'_business_id' => $type === 'business_account' ? $data[$prefix.'_business_id'] : -1,
            $prefix.'_suncash' => $type === 'suncash_wallet' ? $data[$prefix.'_suncash'] : -1,
            $prefix.'_bank_type' => $type === 'bank_deposit' ? $data[$prefix.'_bank_type'] : null,
            $prefix.'_bank_id' => $type === 'bank_deposit' ? $data[$prefix.'_bank_id'] : -1,
            $prefix.'_bank_branch_id' => $type === 'bank_deposit' ? $data[$prefix.'_bank_branch_id'] : -1,
            $prefix.'_bank_acct_name' => $type === 'bank_deposit' ? $data[$prefix.'_bank_acct_name'] : null,
            $bankAccountNumberField => $type === 'bank_deposit' ? $bankAccountNumber : -1,
        ];
    }

    /**
     * @throws ValidationException
     */
    public function create(int $branchId, array $data, User $actor): array
    {
        $errors = $this->validate($data);
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $fields = array_merge(
            [
                'user_type' => KioskThirdPartyUser::USER_TYPE_PARTNER,
                'kiosk_branch_id' => $branchId,
                'terminal_id' => -1,
                'first_name' => trim((string) $data['first_name']),
                'middle_name' => $data['middle_name'] ?? null,
                'last_name' => trim((string) $data['last_name']),
                'email' => trim((string) $data['email']),
                'mobile' => trim((string) $data['mobile']),
                'address' => trim((string) $data['address']),
                'status' => KioskThirdPartyUser::STATUS_ACTIVE,
                'created_by' => $actor->name ?? $actor->email,
                'created_date' => now(),
            ],
            $this->payoutSectionFields($data, 'stl', (bool) ($data['is_settlement'] ?? false)),
            $this->payoutSectionFields($data, 'comm', (bool) ($data['is_commission'] ?? false)),
        );

        $partner = KioskThirdPartyUser::create($fields);

        ActivityLog::recordAction($actor, 'Kiosk Management', 'created_partner', "Added partner {$partner->first_name} {$partner->last_name}", $partner);

        return $this->present($partner);
    }

    /**
     * @throws ValidationException
     */
    public function update(int $id, array $data, User $actor): array
    {
        $partner = $this->findOrFail($id);
        $errors = $this->validate($data);
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $fields = array_merge(
            [
                'first_name' => trim((string) $data['first_name']),
                'middle_name' => $data['middle_name'] ?? null,
                'last_name' => trim((string) $data['last_name']),
                'email' => trim((string) $data['email']),
                'mobile' => trim((string) $data['mobile']),
                'address' => trim((string) $data['address']),
                'updated_by' => $actor->name ?? $actor->email,
                'updated_date' => now()->toDateTimeString(),
            ],
            $this->payoutSectionFields($data, 'stl', (bool) ($data['is_settlement'] ?? false), $partner),
            $this->payoutSectionFields($data, 'comm', (bool) ($data['is_commission'] ?? false), $partner),
        );

        $partner->update($fields);

        ActivityLog::recordAction($actor, 'Kiosk Management', 'updated_partner', "Updated partner {$partner->first_name} {$partner->last_name}", $partner);

        return $this->present($partner);
    }

    /**
     * @throws ValidationException
     */
    public function delete(int $id, User $actor): void
    {
        $partner = $this->findOrFail($id);

        $partner->update([
            'status' => KioskThirdPartyUser::STATUS_DELETED,
            'updated_by' => $actor->name ?? $actor->email,
            'updated_date' => now()->toDateTimeString(),
        ]);

        ActivityLog::recordAction($actor, 'Kiosk Management', 'deleted_partner', "Deleted partner {$partner->first_name} {$partner->last_name}", $partner);
    }
}
