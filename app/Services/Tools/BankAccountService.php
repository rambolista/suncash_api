<?php

namespace App\Services\Tools;

use App\Models\ActivityLog;
use App\Models\Mysuncash\BankAccount;
use App\Models\Mysuncash\BusinessBillpayBank;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * "Tools > Bank Accounts" (legacy `tools/bank_accounts` +
 * `tools_model::get_bank_accounts()`/`save_bank_account()`). SunCash's own
 * house bank accounts used when processing a Cheque settlement — a
 * shared/house-level list, not scoped to any merchant or customer (see
 * `BankAccount` model docblock). Legacy has add + edit, no delete.
 */
class BankAccountService
{
    private function present(BankAccount $account): array
    {
        return [
            'id' => $account->id,
            'business_billpay_banks_id' => $account->business_billpay_banks_id,
            'bank_name' => $account->businessBillpayBank?->banks,
            'branch_info' => $account->businessBillpayBank?->branch_info,
            'account_name' => $account->account_name,
            'account_no' => $account->account_no,
            'create_date' => $account->create_date,
            'modification_date' => $account->modification_date,
        ];
    }

    public function banks(): array
    {
        return BusinessBillpayBank::orderBy('banks')->orderBy('branch_info')
            ->get(['id', 'banks', 'branch_info'])
            ->map(fn (BusinessBillpayBank $b) => ['id' => $b->id, 'name' => "{$b->banks} / {$b->branch_info}"])
            ->all();
    }

    /** Legacy's query filters `status = 'A'` and nothing ever sets another value — active is the only reachable state. */
    public function list(): array
    {
        return BankAccount::with('businessBillpayBank')
            ->where('status', 'A')
            ->orderByDesc('id')
            ->get()
            ->map(fn (BankAccount $a) => $this->present($a))
            ->all();
    }

    private function validate(array $data): array
    {
        $errors = [];
        if (! filled($data['business_billpay_banks_id'] ?? null)) {
            $errors['business_billpay_banks_id'] = ['Please select a bank.'];
        }
        if (! filled($data['account_name'] ?? null)) {
            $errors['account_name'] = ['Please enter the bank account name.'];
        }
        if (! filled($data['account_no'] ?? null)) {
            $errors['account_no'] = ['Please enter the bank account number.'];
        }

        return $errors;
    }

    /** @throws ValidationException */
    public function create(array $data, User $actor): array
    {
        $errors = $this->validate($data);
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $account = BankAccount::create([
            'business_billpay_banks_id' => $data['business_billpay_banks_id'],
            'account_name' => trim($data['account_name']),
            'account_no' => trim($data['account_no']),
            'status' => 'A',
            'create_date' => now(),
        ]);

        ActivityLog::recordCreated($actor, 'Tools - Bank Accounts', $account, ['business_billpay_banks_id', 'account_name', 'account_no']);

        return $this->present($account->load('businessBillpayBank'));
    }

    /** @throws ValidationException */
    public function update(int $id, array $data, User $actor): array
    {
        $account = BankAccount::where('status', 'A')->find($id);
        if (! $account) {
            throw ValidationException::withMessages(['id' => ['This bank account was not found.']]);
        }

        $errors = $this->validate($data);
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $before = $account->getAttributes();
        $account->update([
            'business_billpay_banks_id' => $data['business_billpay_banks_id'],
            'account_name' => trim($data['account_name']),
            'account_no' => trim($data['account_no']),
            'modification_date' => now(),
        ]);

        ActivityLog::recordUpdated($actor, 'Tools - Bank Accounts', $account, $before, ['business_billpay_banks_id', 'account_name', 'account_no', 'modification_date']);

        return $this->present($account->load('businessBillpayBank'));
    }
}
