<?php

namespace App\Services\Kiosk;

use App\Models\ActivityLog;
use App\Models\Mysuncash\Bank;
use App\Models\Mysuncash\BusinessBillpayBank;
use App\Models\Mysuncash\KioskBankAccount;
use App\Models\Mysuncash\KioskBranch;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * "Kiosk Management > Manage Bank Account" (legacy `administrator::
 * get_terminal_bank_accounts()` / `view_bank_terminal()` /
 * `adjust_bank_accounts()` / `delete_terminal_bank()`). Despite the legacy
 * name "terminal bank accounts", every row is branch-level, not per-
 * terminal — `terminal_id` is always hardcoded -1 and the branch is stored
 * in `terminal_branch_id`. Plaintext at rest (unlike the customer-channel
 * rows on the same table) — see `KioskBankAccount` docblock.
 */
class KioskBankAccountService
{
    private function present(KioskBankAccount $account): array
    {
        return [
            'id' => $account->id,
            'branch_id' => $account->terminal_branch_id,
            'branch_name' => $account->branch?->name ?? 'UNASSIGNED',
            'bank_id' => $account->bank_id,
            'bank_name' => $account->bank_name,
            'bank_branch_id' => $account->branch_id,
            'bank_branch_name' => $account->branch_name,
            'account_name' => $account->customer_name,
            'account_number_masked' => $this->mask($account->account_number),
            'account_type' => $account->account_type,
            'created_by' => $account->created_by,
            'create_date' => $account->create_date,
            'updated_by' => $account->updated_by,
            'update_date' => $account->update_date,
        ];
    }

    private function mask(?string $value): ?string
    {
        if (! $value) {
            return null;
        }
        $len = strlen($value);

        return $len <= 4 ? $value : str_repeat('0', $len - 4).substr($value, -4);
    }

    public function list(): array
    {
        return KioskBankAccount::with('branch')
            ->where('user_type', KioskBankAccount::USER_TYPE_TERMINAL)
            ->where('status', KioskBankAccount::STATUS_ACTIVE)
            ->orderByDesc('id')
            ->get()
            ->map(fn (KioskBankAccount $account) => $this->present($account))
            ->all();
    }

    public function listBranches(): array
    {
        return KioskBranch::where('status', KioskBranch::STATUS_ACTIVE)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->all();
    }

    public function listBanks(): array
    {
        return Bank::where('status', 'A')->orderBy('name')->get(['id', 'name'])->all();
    }

    public function listBankBranches(int $bankId): array
    {
        return BusinessBillpayBank::where('bank_id', $bankId)
            ->orderBy('branch_info')
            ->get(['id', 'branch_info'])
            ->map(fn (BusinessBillpayBank $b) => ['id' => $b->id, 'name' => $b->branch_info])
            ->all();
    }

    /**
     * @throws ValidationException
     */
    private function findOrFail(int $id): KioskBankAccount
    {
        $account = KioskBankAccount::where('status', KioskBankAccount::STATUS_ACTIVE)
            ->where('user_type', KioskBankAccount::USER_TYPE_TERMINAL)
            ->find($id);
        if (! $account) {
            throw ValidationException::withMessages(['id' => ['This bank account was not found.']]);
        }

        return $account;
    }

    /**
     * The Edit form's prefill — legacy's `view_bank_terminal(.../edit/...)`
     * passes the account number through UNMASKED into the edit input (only
     * the list row masks it for display); replicated as-is here rather than
     * inventing a masked-resubmission guard legacy never had for this form.
     *
     * @throws ValidationException
     */
    public function show(int $id): array
    {
        $account = $this->findOrFail($id);

        return [
            'id' => $account->id,
            'branch_id' => $account->terminal_branch_id,
            'bank_id' => $account->bank_id,
            'bank_branch_id' => $account->branch_id,
            'account_name' => $account->customer_name,
            'account_number' => $account->account_number,
            'account_type' => $account->account_type,
        ];
    }

    private function validate(array $data): array
    {
        $errors = [];
        if (! filled($data['branch_id'] ?? null) || (int) $data['branch_id'] <= 0) {
            $errors['branch_id'] = ['Please select a kiosk branch.'];
        }
        if (! filled($data['bank_id'] ?? null)) {
            $errors['bank_id'] = ['Please select a bank.'];
        }
        if (! filled($data['bank_branch_id'] ?? null)) {
            $errors['bank_branch_id'] = ['Please select a bank branch.'];
        }
        if (! filled($data['account_name'] ?? null)) {
            $errors['account_name'] = ['Please enter the bank account name.'];
        }
        if (! filled($data['account_number'] ?? null)) {
            $errors['account_number'] = ['Please enter the bank account number.'];
        }
        if (! in_array($data['account_type'] ?? null, ['savings', 'checking'], true)) {
            $errors['account_type'] = ['Please select an account type.'];
        }

        return $errors;
    }

    /**
     * @throws ValidationException
     */
    public function create(array $data, User $actor): array
    {
        $errors = $this->validate($data);
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $bank = Bank::find($data['bank_id']);
        $bankBranch = BusinessBillpayBank::find($data['bank_branch_id']);
        $accountNumber = trim((string) $data['account_number']);
        $accountName = trim((string) $data['account_name']);

        $duplicate = KioskBankAccount::where('bank_id', $data['bank_id'])
            ->where('branch_id', $data['bank_branch_id'])
            ->where('terminal_id', -1)
            ->where('customer_name', $accountName)
            ->where('account_number', $accountNumber)
            ->where('user_type', KioskBankAccount::USER_TYPE_TERMINAL)
            ->where('status', KioskBankAccount::STATUS_ACTIVE)
            ->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['account_number' => ['This bank account has already been added.']]);
        }

        $account = KioskBankAccount::create([
            'customer_number' => -1,
            'bank_id' => $data['bank_id'],
            'branch_id' => $data['bank_branch_id'],
            'bank_name' => $bank?->name,
            'branch_name' => $bankBranch?->branch_info,
            'account_number' => $accountNumber,
            'account_type' => $data['account_type'],
            'customer_name' => $accountName,
            'bank_logo' => $bank?->logo,
            'status' => KioskBankAccount::STATUS_ACTIVE,
            'user_type' => KioskBankAccount::USER_TYPE_TERMINAL,
            'terminal_id' => -1,
            'terminal_branch_id' => $data['branch_id'],
            'created_by' => ($actor->name ?? $actor->email).' - admin',
            'create_date' => now(),
        ]);

        ActivityLog::recordAction($actor, 'Kiosk Management', 'created_bank_account', "Added bank account for kiosk branch #{$account->terminal_branch_id}", $account);

        return $this->present($account->load('branch'));
    }

    /**
     * @throws ValidationException
     */
    public function update(int $id, array $data, User $actor): array
    {
        $account = $this->findOrFail($id);
        $errors = $this->validate($data);
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $bank = Bank::find($data['bank_id']);
        $bankBranch = BusinessBillpayBank::find($data['bank_branch_id']);
        $accountNumber = trim((string) $data['account_number']);
        $accountName = trim((string) $data['account_name']);

        $duplicate = KioskBankAccount::where('id', '!=', $account->id)
            ->where('bank_id', $data['bank_id'])
            ->where('branch_id', $data['bank_branch_id'])
            ->where('terminal_id', -1)
            ->where('customer_name', $accountName)
            ->where('account_number', $accountNumber)
            ->where('user_type', KioskBankAccount::USER_TYPE_TERMINAL)
            ->where('status', KioskBankAccount::STATUS_ACTIVE)
            ->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['account_number' => ['This bank account has already been added.']]);
        }

        $account->update([
            'bank_id' => $data['bank_id'],
            'branch_id' => $data['bank_branch_id'],
            'bank_name' => $bank?->name,
            'branch_name' => $bankBranch?->branch_info,
            'account_number' => $accountNumber,
            'account_type' => $data['account_type'],
            'customer_name' => $accountName,
            'bank_logo' => $bank?->logo,
            'terminal_branch_id' => $data['branch_id'],
            'updated_by' => $actor->name ?? $actor->email,
            'update_date' => now(),
        ]);

        ActivityLog::recordAction($actor, 'Kiosk Management', 'updated_bank_account', "Updated bank account #{$account->id} for kiosk branch #{$account->terminal_branch_id}", $account);

        return $this->present($account->load('branch'));
    }

    /**
     * @throws ValidationException
     */
    public function delete(int $id, User $actor): void
    {
        $account = $this->findOrFail($id);

        $account->update([
            'status' => KioskBankAccount::STATUS_DELETED,
            'updated_by' => $actor->name ?? $actor->email,
            'update_date' => now(),
        ]);

        ActivityLog::recordAction($actor, 'Kiosk Management', 'deleted_bank_account', "Deleted bank account #{$account->id}", $account);
    }
}
