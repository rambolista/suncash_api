<?php

namespace App\Services\FloatManagement;

use App\Models\Mysuncash\CashierAccountReplenishment;
use App\Models\Mysuncash\CashierMainReserveAccount;
use App\Models\Mysuncash\CashierReserveAccountLog;
use App\Models\Mysuncash\CashierStoreFloatAccount;
use App\Models\Mysuncash\Merchant;
use App\Services\FloatManagement\Concerns\GeneratesTransactionId;
use Illuminate\Validation\ValidationException;

/**
 * Per-merchant "store float" accounts — mirrors legacy admin's Float
 * Management > Store Float Replenishments & Current Store Float Amounts
 * pages, plus a working "set up an account" flow (legacy's own attempt at
 * this — `tools/store_setup` — posted to an unrelated controller and its
 * "Create an Account" link was never actually rendered; this rebuilds it
 * properly on top of the same `createStoreFloatAccount` model logic legacy
 * used elsewhere).
 *
 * `cashier_store_float_accounts.status` lifecycle: PENDING (requested) ->
 * APPROVED (thresholds accepted, account live) or REJECTED. This table's
 * `CONFIRMED` status (also defined on Merchant\MerchantFloatAccountService's
 * model) is never actually reached by any reachable flow, on legacy or here —
 * that service's own edit form keys off status === APPROVED to keep showing
 * an account, so this service treats APPROVED as the active/live state and
 * only credits the account's balance on confirm, without touching its status.
 * Both APPROVED and CONFIRMED are still accepted as "active" wherever this
 * service reads the status, purely defensively.
 */
class StoreFloatAccountService
{
    use GeneratesTransactionId;

    private const ACTIVE_STATUSES = [CashierStoreFloatAccount::APPROVED, CashierStoreFloatAccount::CONFIRMED];

    public function listReplenishments(): array
    {
        $base = fn () => CashierAccountReplenishment::with('merchant')->where('is_main_reserve', 0);

        return [
            'pending' => $base()->where('status', CashierAccountReplenishment::PENDING)->orderByDesc('id')->get(),
            // Legacy's Approved tab intentionally shows both still-approved and already-confirmed rows.
            'approved' => $base()->whereIn('status', [CashierAccountReplenishment::APPROVED, CashierAccountReplenishment::CONFIRMED])->orderByDesc('id')->get(),
            'rejected' => $base()->where('status', CashierAccountReplenishment::REJECTED)->orderByDesc('id')->get(),
        ];
    }

    public function listCurrentAmounts(?string $searchType = null, ?string $searchValue = null): array
    {
        $merchantIds = CashierStoreFloatAccount::whereIn('status', self::ACTIVE_STATUSES)
            ->distinct()
            ->pluck('merchant_id');

        $query = CashierStoreFloatAccount::with('merchant')->whereIn('merchant_id', $merchantIds);

        if ($searchType === 'merchant_id' && filled($searchValue)) {
            $query->where('merchant_id', (int) $searchValue);
        } elseif ($searchType === 'merchant_name' && filled($searchValue)) {
            $query->whereHas('merchant', function ($q) use ($searchValue) {
                $q->where('dba_name', $searchValue)->orWhere('legal_name', $searchValue);
            });
        }

        // Ordered newest-first, then de-duped to the latest row per merchant — legacy's equivalent
        // GROUP BY had no ORDER BY, so the row picked per merchant wasn't guaranteed to be the latest one.
        return $query->orderByDesc('id')->get()->unique('merchant_id')->values()->all();
    }

    private function latestForMerchant(int $merchantId, array|string|null $status = null): ?CashierStoreFloatAccount
    {
        return CashierStoreFloatAccount::where('merchant_id', $merchantId)
            ->when($status, fn ($q) => is_array($status) ? $q->whereIn('status', $status) : $q->where('status', $status))
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @throws ValidationException
     */
    public function createAccount(int $merchantId, float $min, float $max, string $email, string $username): CashierStoreFloatAccount
    {
        if (! str_contains($email, '@')) {
            throw ValidationException::withMessages(['email_address' => ['Enter a valid email address.']]);
        }
        if ($min <= 0 || $max <= 0 || $min >= $max) {
            throw ValidationException::withMessages(['minimum_account' => ['Minimum threshold must be a positive number less than the maximum threshold.']]);
        }

        $existing = $this->latestForMerchant($merchantId);
        if ($existing && $existing->status === CashierStoreFloatAccount::PENDING) {
            throw ValidationException::withMessages(['merchant_id' => ['There is already a pending store float request for this merchant.']]);
        }
        if ($existing && in_array($existing->status, self::ACTIVE_STATUSES, true)) {
            throw ValidationException::withMessages(['merchant_id' => ['This merchant already has an active store float account.']]);
        }

        $account = CashierStoreFloatAccount::create([
            'merchant_id' => $merchantId,
            'minimum_account' => $min,
            'maximum_account' => $max,
            'email_address' => $email,
            'create_by' => $username,
            'create_date' => now(),
            'status' => CashierStoreFloatAccount::PENDING,
        ]);

        CashierAccountReplenishment::create([
            'transaction_id' => $this->generateTransactionId(CashierAccountReplenishment::class),
            'merchant_id' => $merchantId,
            'amount' => $max,
            'create_by' => $username,
            'create_date' => now(),
            'status' => CashierAccountReplenishment::PENDING,
            'is_main_reserve' => 0,
        ]);

        return $account;
    }

    /**
     * @throws ValidationException
     */
    public function approveReplenishment(int $id, string $username): CashierAccountReplenishment
    {
        $replenishment = CashierAccountReplenishment::where('is_main_reserve', 0)
            ->where('status', CashierAccountReplenishment::PENDING)
            ->find($id);
        if (! $replenishment) {
            throw ValidationException::withMessages(['id' => ['Pending store float replenishment not found.']]);
        }

        // Preserve compatibility with the legacy webpos flow, which still writes into this
        // shared table: a webpos-originated request is auto-confirmed and moves money immediately.
        if ((int) $replenishment->is_webpos_request === 1) {
            $replenishment->update([
                'status' => CashierAccountReplenishment::CONFIRMED,
                'approve_by' => $username,
                'approve_date' => now(),
            ]);

            Merchant::where('id', $replenishment->merchant_id)->increment('cash_float_account', $replenishment->amount);
            Merchant::where('id', $replenishment->merchant_id)->decrement('reserve_account', $replenishment->amount);

            return $replenishment->fresh();
        }

        $account = $this->latestForMerchant($replenishment->merchant_id);
        if ($account) {
            $account->update([
                'status' => CashierStoreFloatAccount::APPROVED,
                'approve_by' => $username,
                'approve_date' => now(),
            ]);
        }

        $replenishment->update([
            'status' => CashierAccountReplenishment::APPROVED,
            'approve_by' => $username,
            'approve_date' => now(),
        ]);

        return $replenishment->fresh();
    }

    /**
     * @throws ValidationException
     */
    public function rejectReplenishment(int $id, string $username): CashierAccountReplenishment
    {
        $replenishment = CashierAccountReplenishment::where('is_main_reserve', 0)
            ->where('status', CashierAccountReplenishment::PENDING)
            ->find($id);
        if (! $replenishment) {
            throw ValidationException::withMessages(['id' => ['Pending store float replenishment not found.']]);
        }

        $replenishment->update([
            'status' => CashierAccountReplenishment::REJECTED,
            'rejected_by' => $username,
            'rejected_date' => now(),
        ]);

        $account = $this->latestForMerchant($replenishment->merchant_id, CashierStoreFloatAccount::PENDING);
        if ($account) {
            $account->update([
                'status' => CashierStoreFloatAccount::REJECTED,
                'rejected_by' => $username,
                'rejected_date' => now(),
            ]);
        }

        return $replenishment->fresh();
    }

    /**
     * Finalizes an approved replenishment: credits the store's float balance, debits the main
     * reserve pool, and credits the merchant's own reserve_account exactly once (legacy's
     * `confirmReplenishment` double-credited `clients.reserve_account` — fixed here). The store
     * float account itself stays APPROVED (not CONFIRMED) — Merchant\MerchantFloatAccountService's
     * own edit form on the Merchant Management tab keys off status === APPROVED to keep showing
     * the account, so flipping it to CONFIRMED here would hide it there.
     *
     * @throws ValidationException
     */
    public function confirmReplenishment(int $id, string $username): CashierAccountReplenishment
    {
        $replenishment = CashierAccountReplenishment::where('is_main_reserve', 0)
            ->where('status', CashierAccountReplenishment::APPROVED)
            ->find($id);
        if (! $replenishment) {
            throw ValidationException::withMessages(['id' => ['Approved store float replenishment not found.']]);
        }

        $amount = (float) $replenishment->amount;

        $account = $this->latestForMerchant($replenishment->merchant_id, self::ACTIVE_STATUSES);
        if ($account) {
            $account->update([
                'amount' => (float) $account->amount + $amount,
                'confirm_by' => $username,
                'confirm_date' => now(),
            ]);
        }

        $mainReserve = CashierMainReserveAccount::where('status', CashierMainReserveAccount::APPROVED)->orderByDesc('id')->first();
        if ($mainReserve) {
            $mainReserve->update(['amount' => (float) $mainReserve->amount - $amount]);
        }

        Merchant::where('id', $replenishment->merchant_id)->increment('reserve_account', $amount);

        $replenishment->update([
            'status' => CashierAccountReplenishment::CONFIRMED,
            'confirm_by' => $username,
            'confirm_date' => now(),
        ]);

        return $replenishment->fresh();
    }

    /**
     * Self-serve immediate credit to an active store's float balance — no approval step.
     *
     * @throws ValidationException
     */
    public function topup(int $storeFloatId, float $amount, string $username): CashierStoreFloatAccount
    {
        $account = CashierStoreFloatAccount::whereIn('status', self::ACTIVE_STATUSES)->find($storeFloatId);
        if (! $account) {
            throw ValidationException::withMessages(['id' => ['Active store float account not found.']]);
        }

        $current = (float) $account->amount;
        $recommended = (float) $account->maximum_account - $current;

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => ['Enter a valid amount.']]);
        }
        if ($recommended <= 0) {
            throw ValidationException::withMessages(['amount' => ['This store is already at its maximum balance.']]);
        }
        if ($amount > $recommended || $amount > (float) $account->maximum_account) {
            throw ValidationException::withMessages(['amount' => ["Amount should not exceed the maximum threshold. Recommended amount is BSD {$recommended}."]]);
        }
        if ($amount < (float) $account->minimum_account) {
            throw ValidationException::withMessages(['amount' => ["Amount should not be less than the minimum threshold of BSD {$account->minimum_account}."]]);
        }

        CashierReserveAccountLog::create([
            'reserve_request_id' => $account->id,
            'purpose' => 'store_float_topup',
            'amount' => $amount,
            'create_by' => $username,
        ]);

        $account->update(['amount' => $current + $amount]);

        return $account->fresh();
    }

    /**
     * Requests an approval-gated replenishment for an existing, active store float account.
     *
     * @throws ValidationException
     */
    public function requestReplenishment(int $storeFloatId, float $amount, string $username): CashierAccountReplenishment
    {
        $account = CashierStoreFloatAccount::whereIn('status', self::ACTIVE_STATUSES)->find($storeFloatId);
        if (! $account) {
            throw ValidationException::withMessages(['id' => ['Active store float account not found.']]);
        }

        if (CashierAccountReplenishment::where('merchant_id', $account->merchant_id)
            ->where('is_main_reserve', 0)
            ->where('status', CashierAccountReplenishment::PENDING)
            ->exists()
        ) {
            throw ValidationException::withMessages(['status' => ['There is already a pending request for this store.']]);
        }

        $current = (float) $account->amount;
        $recommended = (float) $account->maximum_account - $current;

        if ($amount <= 0 || $recommended <= 0) {
            throw ValidationException::withMessages(['amount' => ['This store already has enough balance.']]);
        }
        if ($amount > $recommended || $amount > (float) $account->maximum_account) {
            throw ValidationException::withMessages(['amount' => ["Amount should not exceed the maximum threshold. Recommended amount is BSD {$recommended}."]]);
        }
        if ($amount < (float) $account->minimum_account) {
            throw ValidationException::withMessages(['amount' => ["Amount should not be less than the minimum threshold of BSD {$account->minimum_account}."]]);
        }

        $replenishment = CashierAccountReplenishment::create([
            'transaction_id' => $this->generateTransactionId(CashierAccountReplenishment::class),
            'merchant_id' => $account->merchant_id,
            'amount' => $amount,
            'create_by' => $username,
            'create_date' => now(),
            'status' => CashierAccountReplenishment::PENDING,
            'is_main_reserve' => 0,
        ]);

        CashierReserveAccountLog::create([
            'reserve_request_id' => $replenishment->id,
            'purpose' => 'store_float_replenishment',
            'amount' => $amount,
            'create_by' => $username,
        ]);

        return $replenishment;
    }
}
