<?php

namespace App\Services\Tools;

use App\Models\ActivityLog;
use App\Models\Mysuncash\TransactionLimit;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * "Tools > Transaction Limits" (legacy `tools/transaction_limit` +
 * `tools_model::get_transaction_limit()`/`update_transaction_limit()`). A
 * fixed, pre-seeded lookup of the transaction limit per customer KYC tier
 * (quickstart/full/pending/rejected) — no add/delete, only the amount is
 * editable. `KycUpgradeService` reads these same rows live for actual limit
 * enforcement, so an edit here takes effect immediately.
 */
class TransactionLimitService
{
    public function list(): array
    {
        return TransactionLimit::orderByDesc('id')
            ->get(['id', 'type', 'transaction_limit', 'create_date', 'update_date'])
            ->toArray();
    }

    /** @throws ValidationException */
    public function update(int $id, float $amount, User $actor): TransactionLimit
    {
        $limit = TransactionLimit::find($id);
        if (! $limit) {
            throw ValidationException::withMessages(['id' => ['This transaction limit record was not found.']]);
        }

        $before = $limit->getAttributes();
        $limit->update(['transaction_limit' => $amount, 'update_date' => now()]);

        ActivityLog::recordUpdated($actor, 'Tools - Transaction Limits', $limit, $before, ['transaction_limit', 'update_date']);

        return $limit;
    }
}
