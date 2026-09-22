<?php

namespace App\Services\Tools;

use App\Models\ActivityLog;
use App\Models\Mysuncash\CreditCardFee;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * "Tools > Credit Card Fees" (legacy `tools/creditcard_fees` +
 * `tools_model::get_creditcard_fees()`/`update_creditcard_fee()`). A fixed,
 * pre-seeded lookup of credit-card processing/transaction fees
 * (Processing/Transaction/Customer_Processing/Customer_Transaction) — no
 * add/delete, only the amount is editable.
 *
 * `credit_card_fees.user_id_modify` exists but legacy's own update never
 * sets it — populated here since the acting admin is already on hand
 * (fixes a real gap rather than replicating it).
 */
class CreditCardFeeService
{
    public function list(): array
    {
        return CreditCardFee::orderByDesc('id')
            ->get(['id', 'type', 'value', 'creation_date', 'modification_date'])
            ->toArray();
    }

    /** @throws ValidationException */
    public function update(int $id, float $amount, User $actor): CreditCardFee
    {
        $fee = CreditCardFee::find($id);
        if (! $fee) {
            throw ValidationException::withMessages(['id' => ['This fee record was not found.']]);
        }

        $before = $fee->getAttributes();
        $fee->update(['value' => $amount, 'modification_date' => now(), 'user_id_modify' => $actor->id]);

        ActivityLog::recordUpdated($actor, 'Tools - Credit Card Fees', $fee, $before, ['value', 'modification_date', 'user_id_modify']);

        return $fee;
    }
}
