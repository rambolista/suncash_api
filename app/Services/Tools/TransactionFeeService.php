<?php

namespace App\Services\Tools;

use App\Models\ActivityLog;
use App\Models\Mysuncash\CustomerTransactionFee;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * "Tools > Transaction Fees" (legacy `tools/transaction_fee` +
 * `tools_model::get_transaction_fee()`/`update_transaction_fee()`). A fixed,
 * pre-seeded lookup of the flat fee charged per transaction type
 * (SENDMONEY/BILLPAY/DONATE) — no add/delete, only the amount is editable.
 */
class TransactionFeeService
{
    public function list(): array
    {
        return CustomerTransactionFee::orderByDesc('id')
            ->get(['id', 'transaction_type', 'transaction_fee', 'create_date', 'update_date'])
            ->toArray();
    }

    /** @throws ValidationException */
    public function update(int $id, float $amount, User $actor): CustomerTransactionFee
    {
        $fee = CustomerTransactionFee::find($id);
        if (! $fee) {
            throw ValidationException::withMessages(['id' => ['This transaction fee record was not found.']]);
        }

        $before = $fee->getAttributes();
        $fee->update(['transaction_fee' => $amount, 'update_date' => now()]);

        ActivityLog::recordUpdated($actor, 'Tools - Transaction Fees', $fee, $before, ['transaction_fee', 'update_date']);

        return $fee;
    }
}
