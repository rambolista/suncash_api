<?php

namespace App\Services\FloatManagement;

use App\Models\Mysuncash\CashierMainReserveAccount;
use App\Models\Mysuncash\CashierReserveAccountLog;
use App\Services\FloatManagement\Concerns\GeneratesTransactionId;
use Illuminate\Validation\ValidationException;

/**
 * The single, company-wide "Main Reserve Account" float pool — mirrors legacy
 * admin's Float Management > Main Reserve Account & Set Main Reserve Account
 * pages. Legacy's `setup_reserve_account`'s "update" branch validated
 * min/max against exact constants instead of a range (a bug); this port
 * validates the same range used on setup, on both create and update.
 */
class MainReserveAccountService
{
    private const MIN_THRESHOLD = 10000;

    private const MAX_THRESHOLD = 40000;

    use GeneratesTransactionId;

    public function list(): array
    {
        return [
            'pending' => CashierMainReserveAccount::where('status', CashierMainReserveAccount::PENDING)->orderByDesc('id')->get(),
            'approved' => CashierMainReserveAccount::where('status', CashierMainReserveAccount::APPROVED)->orderByDesc('id')->get(),
            'rejected' => CashierMainReserveAccount::where('status', CashierMainReserveAccount::REJECTED)->orderByDesc('id')->get(),
        ];
    }

    /** The account record shown on the "Set Main Reserve Account" screen — the single most recent row, whatever its status. */
    public function current(): ?CashierMainReserveAccount
    {
        return CashierMainReserveAccount::orderByDesc('id')->first();
    }

    private function latestApproved(): ?CashierMainReserveAccount
    {
        return CashierMainReserveAccount::where('status', CashierMainReserveAccount::APPROVED)->orderByDesc('id')->first();
    }

    private function validateThresholds(float $min, float $max, string $email): void
    {
        $errors = [];

        if ($min < self::MIN_THRESHOLD) {
            $errors['minimum_account'] = ['Minimum threshold must be at least BSD '.self::MIN_THRESHOLD.'.'];
        }
        if ($max > self::MAX_THRESHOLD) {
            $errors['maximum_account'] = ['Maximum threshold cannot exceed BSD '.self::MAX_THRESHOLD.'.'];
        }
        if ($min >= $max) {
            $errors['minimum_account'] = ['Minimum threshold must be less than the maximum threshold.'];
        }
        if (! str_contains($email, '@')) {
            $errors['email_address'] = ['Enter a valid email address.'];
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * First-time setup — creates the account's opening PENDING request, awaiting approval on the Main Reserve Account page.
     *
     * @throws ValidationException
     */
    public function setup(array $data, string $username): CashierMainReserveAccount
    {
        if (CashierMainReserveAccount::whereIn('status', [CashierMainReserveAccount::PENDING, CashierMainReserveAccount::APPROVED])->exists()) {
            throw ValidationException::withMessages(['status' => ['A main reserve account is already set up or pending approval.']]);
        }

        $min = (float) ($data['minimum_account'] ?? 0);
        $max = (float) ($data['maximum_account'] ?? 0);
        $email = (string) ($data['email_address'] ?? '');
        $amount = (float) ($data['amount'] ?? 0);

        $this->validateThresholds($min, $max, $email);

        if ($amount < $min || $amount > $max) {
            throw ValidationException::withMessages(['amount' => ["Enter an amount between BSD {$min} and BSD {$max}."]]);
        }

        $account = CashierMainReserveAccount::create([
            'transaction_id' => $this->generateTransactionId(CashierMainReserveAccount::class),
            'minimum_account' => $min,
            'maximum_account' => $max,
            'repl_amount' => $amount,
            'email_address' => $email,
            'create_by' => $username,
            'create_date' => now(),
            'status' => CashierMainReserveAccount::PENDING,
        ]);

        CashierReserveAccountLog::create([
            'reserve_request_id' => $account->id,
            'purpose' => 'main_reserve_account',
            'amount' => $amount,
            'create_by' => $username,
        ]);

        return $account;
    }

    /**
     * In-place threshold/email edit once the account is APPROVED — no re-approval cycle.
     *
     * @throws ValidationException
     */
    public function update(array $data, string $username): CashierMainReserveAccount
    {
        $account = $this->latestApproved();
        if (! $account) {
            throw ValidationException::withMessages(['status' => ['There is no approved main reserve account to update.']]);
        }

        $min = (float) ($data['minimum_account'] ?? 0);
        $max = (float) ($data['maximum_account'] ?? 0);
        $email = (string) ($data['email_address'] ?? '');

        $this->validateThresholds($min, $max, $email);

        $account->update([
            'minimum_account' => $min,
            'maximum_account' => $max,
            'email_address' => $email,
            'updated_by' => $username,
            'updated_date' => now(),
        ]);

        return $account->fresh();
    }

    /**
     * @throws ValidationException
     */
    public function approve(int $id, string $username): CashierMainReserveAccount
    {
        $account = CashierMainReserveAccount::where('status', CashierMainReserveAccount::PENDING)->find($id);
        if (! $account) {
            throw ValidationException::withMessages(['id' => ['Pending main reserve request not found.']]);
        }

        $account->update([
            'status' => CashierMainReserveAccount::APPROVED,
            'approve_date' => now(),
            'approve_by' => $username,
        ]);

        return $account->fresh();
    }

    /**
     * @throws ValidationException
     */
    public function reject(int $id, string $username): CashierMainReserveAccount
    {
        $account = CashierMainReserveAccount::where('status', CashierMainReserveAccount::PENDING)->find($id);
        if (! $account) {
            throw ValidationException::withMessages(['id' => ['Pending main reserve request not found.']]);
        }

        $account->update([
            'status' => CashierMainReserveAccount::REJECTED,
            'rejected_date' => now(),
            'rejected_by' => $username,
        ]);

        return $account->fresh();
    }

    /**
     * "Confirm" on an approved replenishment row — credits its requested amount into the running balance.
     *
     * @throws ValidationException
     */
    public function confirm(int $id): CashierMainReserveAccount
    {
        $account = CashierMainReserveAccount::where('status', CashierMainReserveAccount::APPROVED)
            ->where('is_confirm', 0)
            ->find($id);
        if (! $account) {
            throw ValidationException::withMessages(['id' => ['Approved, unconfirmed main reserve row not found.']]);
        }

        $account->update([
            'amount' => (float) $account->amount + (float) $account->repl_amount,
            'is_confirm' => 1,
        ]);

        return $account->fresh();
    }

    /**
     * Direct top-up of the approved account's running balance.
     *
     * @throws ValidationException
     */
    public function topup(float $amount, string $username): CashierMainReserveAccount
    {
        $account = $this->latestApproved();
        if (! $account) {
            throw ValidationException::withMessages(['status' => ['There is no approved main reserve account to top up.']]);
        }

        $recommended = (float) $account->maximum_account - (float) $account->amount;

        if ($amount > (float) $account->maximum_account) {
            throw ValidationException::withMessages(['amount' => ["Maximum amount is BSD {$account->maximum_account}."]]);
        }
        if ($amount > $recommended) {
            throw ValidationException::withMessages(['amount' => ["The recommended amount is BSD {$recommended}."]]);
        }

        CashierReserveAccountLog::create([
            'reserve_request_id' => $account->id,
            'purpose' => 'reserve_account_topup',
            'amount' => $amount,
            'create_by' => $username,
        ]);

        $account->update(['amount' => (float) $account->amount + $amount]);

        return $account->fresh();
    }

    /**
     * Requests a new replenishment — creates a fresh PENDING row awaiting approval on the Main Reserve Account page.
     *
     * @throws ValidationException
     */
    public function requestReplenishment(float $amount, string $username): CashierMainReserveAccount
    {
        if (CashierMainReserveAccount::where('status', CashierMainReserveAccount::PENDING)->exists()) {
            throw ValidationException::withMessages(['status' => ["There's already a pending replenishment request."]]);
        }

        $approved = $this->latestApproved();
        if (! $approved) {
            throw ValidationException::withMessages(['status' => ['There is no approved main reserve account to replenish.']]);
        }

        $recommended = (float) $approved->maximum_account - (float) $approved->amount;

        if ($amount > $recommended) {
            throw ValidationException::withMessages(['amount' => ["The recommended amount is BSD {$recommended}."]]);
        }
        if ($amount < (float) $approved->minimum_account) {
            throw ValidationException::withMessages(['amount' => ["The minimum amount is BSD {$approved->minimum_account}."]]);
        }
        if ($amount > (float) $approved->maximum_account) {
            throw ValidationException::withMessages(['amount' => ["The maximum amount is BSD {$approved->maximum_account}."]]);
        }

        $account = CashierMainReserveAccount::create([
            'transaction_id' => $this->generateTransactionId(CashierMainReserveAccount::class),
            'maximum_account' => $approved->maximum_account,
            'minimum_account' => $approved->minimum_account,
            'repl_amount' => $amount,
            'amount' => $approved->amount,
            'email_address' => $approved->email_address,
            'create_by' => $username,
            'create_date' => now(),
            'status' => CashierMainReserveAccount::PENDING,
        ]);

        CashierReserveAccountLog::create([
            'reserve_request_id' => $account->id,
            'purpose' => 'main_reserve_replenishment',
            'amount' => $amount,
            'create_by' => $username,
        ]);

        return $account;
    }
}
