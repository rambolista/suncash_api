<?php

namespace App\Services\Merchant;

use App\Models\Mysuncash\CashierMainReserveAccount;
use App\Models\Mysuncash\CashierStoreFloatAccount;
use App\Models\Mysuncash\CashierStoreFloatSetting;
use App\Models\Mysuncash\Merchant;
use Illuminate\Validation\ValidationException;

/**
 * Store Float Account — a request/approval workflow, not a direct CRUD
 * screen. This service covers what the admin panel's per-merchant button
 * exposes: the enable/disable toggle, submitting a new request, and editing
 * an already-approved account's thresholds. Approving/rejecting a pending
 * request is a separate admin screen in the legacy app, out of scope here.
 */
class MerchantFloatAccountService
{
    private function findMerchantOrFail(int $merchantId): Merchant
    {
        $merchant = Merchant::find($merchantId);
        if (! $merchant) {
            throw ValidationException::withMessages(['id' => ['Merchant not found.']]);
        }

        return $merchant;
    }

    public function getState(int $merchantId): array
    {
        $this->findMerchantOrFail($merchantId);

        $setting = CashierStoreFloatSetting::where('merchant_id', $merchantId)->first();
        $approvedAccount = CashierStoreFloatAccount::where('merchant_id', $merchantId)
            ->where('status', CashierStoreFloatAccount::APPROVED)
            ->orderByDesc('id')
            ->first();
        $latestRequest = CashierStoreFloatAccount::where('merchant_id', $merchantId)
            ->orderByDesc('id')
            ->first();

        return [
            'enabled' => $setting?->status === CashierStoreFloatSetting::ENABLED,
            'approved_account' => $approvedAccount?->only(['id', 'minimum_account', 'maximum_account', 'amount', 'email_address']),
            'latest_request' => $latestRequest?->only(['id', 'status', 'minimum_account', 'maximum_account', 'email_address']),
        ];
    }

    /**
     * @throws ValidationException
     */
    public function toggleEnabled(int $merchantId, string $actorId): array
    {
        $this->findMerchantOrFail($merchantId);

        $hasApprovedMainReserve = CashierMainReserveAccount::where('status', CashierMainReserveAccount::APPROVED)->exists();
        if (! $hasApprovedMainReserve) {
            throw ValidationException::withMessages(['id' => ['Please set up a Suncash main reserve account first.']]);
        }

        $setting = CashierStoreFloatSetting::where('merchant_id', $merchantId)->first();

        if ($setting) {
            $newStatus = $setting->status === CashierStoreFloatSetting::ENABLED
                ? CashierStoreFloatSetting::DISABLED
                : CashierStoreFloatSetting::ENABLED;
            $setting->update(['status' => $newStatus, 'update_by' => $actorId]);
        } else {
            $newStatus = CashierStoreFloatSetting::ENABLED;
            CashierStoreFloatSetting::create(['merchant_id' => $merchantId, 'status' => $newStatus, 'create_by' => $actorId]);
        }

        return ['enabled' => $newStatus === CashierStoreFloatSetting::ENABLED];
    }

    /**
     * @throws ValidationException
     */
    public function requestFloatAccount(int $merchantId, array $data, string $actorId): array
    {
        $this->findMerchantOrFail($merchantId);

        $min = $data['minimum_account'] ?? null;
        $max = $data['maximum_account'] ?? null;
        $email = trim((string) ($data['email_address'] ?? ''));

        $errors = [];
        if (! is_numeric($min)) {
            $errors['minimum_account'] = ['Enter a valid minimum amount.'];
        }
        if (! is_numeric($max)) {
            $errors['maximum_account'] = ['Enter a valid maximum amount.'];
        }
        if (! filled($email)) {
            $errors['email_address'] = ['Notification e-mail is required.'];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $latest = CashierStoreFloatAccount::where('merchant_id', $merchantId)->orderByDesc('id')->first();
        if ($latest?->status === CashierStoreFloatAccount::PENDING) {
            throw ValidationException::withMessages(['id' => ['There is already a pending request for this merchant.']]);
        }
        if ($latest?->status === CashierStoreFloatAccount::CONFIRMED) {
            throw ValidationException::withMessages(['id' => ['A store float account is already set up for this merchant.']]);
        }

        $request = CashierStoreFloatAccount::create([
            'merchant_id' => $merchantId,
            'minimum_account' => (float) $min,
            'maximum_account' => (float) $max,
            'email_address' => $email,
            'status' => CashierStoreFloatAccount::PENDING,
            'create_by' => $actorId,
        ]);

        return $request->only(['id', 'status', 'minimum_account', 'maximum_account', 'email_address']);
    }

    /**
     * @throws ValidationException
     */
    public function updateFloatAccount(int $merchantId, array $data, string $actorId): array
    {
        $this->findMerchantOrFail($merchantId);

        $account = CashierStoreFloatAccount::where('merchant_id', $merchantId)
            ->where('status', CashierStoreFloatAccount::APPROVED)
            ->orderByDesc('id')
            ->first();

        if (! $account) {
            throw ValidationException::withMessages(['id' => ['No approved store float account to edit.']]);
        }

        $min = $data['minimum_account'] ?? $account->minimum_account;
        $max = $data['maximum_account'] ?? $account->maximum_account;
        $email = trim((string) ($data['email_address'] ?? $account->email_address));

        if (! is_numeric($min) || ! is_numeric($max)) {
            throw ValidationException::withMessages(['minimum_account' => ['Enter valid amounts.']]);
        }
        if (! filled($email)) {
            throw ValidationException::withMessages(['email_address' => ['Notification e-mail is required.']]);
        }

        $account->update([
            'minimum_account' => (float) $min,
            'maximum_account' => (float) $max,
            'email_address' => $email,
            'update_by' => $actorId,
        ]);

        return $account->only(['id', 'status', 'minimum_account', 'maximum_account', 'email_address']);
    }
}
