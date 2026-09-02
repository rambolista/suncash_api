<?php

namespace App\Services\Transactions\Support;

use App\Models\Mysuncash\ClientTransaction;
use App\Models\Mysuncash\ClientTransactionDetail;
use App\Models\Mysuncash\Customer;
use App\Models\Mysuncash\CustomerTransactionHistory;
use App\Models\Mysuncash\EzkardAccount;
use App\Models\Mysuncash\EzkardTransaction;
use App\Models\Mysuncash\Merchant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The money-movement primitives Void Transaction reverses balances with —
 * split out so any future feature that needs to move money into/out of a
 * merchant's `clients.client_{prefund|settlement|fee}` bucket or a
 * customer's `ezkard_accounts.card_balance` (both with their matching
 * ledger-row insert) can reuse the exact same, already-verified logic
 * instead of re-deriving it.
 */
class LedgerAdjuster
{
    public function nextTransactionId(): string
    {
        $id = (string) time();
        while (DB::connection('mysuncash')->table('ezkard_transactions')->where('transaction_id', $id)->exists()) {
            $id = (string) (((int) $id) + 1);
        }

        return $id;
    }

    /**
     * Mirrors legacy's `_adjust_client_balance()` — moves money into/out of
     * one of a merchant's `clients.client_{prefund|settlement|fee}` buckets,
     * logging a `client_transactions` + `client_transaction_details` row.
     *
     * @throws ValidationException
     */
    public function adjustClientBalance(int $clientId, string $accountType, string $direction, float $amount, int $transTypeId, string $description, int|string|null $refTransId = null): void
    {
        $merchant = Merchant::find($clientId);
        if (! $merchant) {
            throw ValidationException::withMessages(['id' => ['Merchant account not found.']]);
        }

        $field = match ($accountType) {
            'prefund' => 'client_prefund',
            'settlement' => 'client_settlement',
            'fee' => 'client_fee',
        };
        $detailType = match ($accountType) {
            'prefund' => ClientTransactionDetail::ACCOUNT_TYPE_PREFUND,
            'settlement' => ClientTransactionDetail::ACCOUNT_TYPE_SETTLEMENT,
            'fee' => ClientTransactionDetail::ACCOUNT_TYPE_FEE,
        };

        if ($direction === 'less' && (float) $merchant->{$field} < $amount) {
            throw ValidationException::withMessages(['amount' => ['Insufficient merchant balance.']]);
        }

        $direction === 'add' ? $merchant->increment($field, $amount) : $merchant->decrement($field, $amount);
        $merchant->refresh();

        $clientTransaction = ClientTransaction::create([
            'client_record_id' => $clientId,
            'user_type_id' => 1,
            'ref_trans_id' => $refTransId,
            'trans_type_id' => $transTypeId,
            'amount' => $amount,
            'description' => $description,
            'timestamp' => now(),
            'is_merchant' => 1,
            'merchant_id' => $clientId,
            'running_balance' => $merchant->{$field},
            'available_balance' => $merchant->{$field},
        ]);

        ClientTransactionDetail::create([
            'client_transaction_id' => $clientTransaction->id,
            'client_account_type' => $detailType,
            'client_record_id' => $clientId,
            'amount' => $amount,
        ]);
    }

    /**
     * Mirrors legacy's `_adjust_card_account()`/`_adjust_wallet_account()` —
     * moves money into/out of a customer's `ezkard_accounts.card_balance`
     * and logs a brand-new `ezkard_transactions` row for it.
     *
     * @throws ValidationException
     */
    public function adjustCardBalance(int $ezkardId, string $direction, float $amount, int $transTypeId, string $description, ?int $merchantId = null, int|string|null $referenceId = null): EzkardTransaction
    {
        $ezkard = EzkardAccount::find($ezkardId);
        if (! $ezkard) {
            throw ValidationException::withMessages(['id' => ['Linked card account not found.']]);
        }
        if ($direction === 'less' && (float) $ezkard->card_balance < $amount) {
            throw ValidationException::withMessages(['amount' => ['Insufficient customer balance.']]);
        }

        if ($direction === 'add') {
            DB::connection('mysuncash')->table('ezkard_accounts')->where('id', $ezkardId)->increment('card_balance', $amount);
        } else {
            DB::connection('mysuncash')->table('ezkard_accounts')->where('id', $ezkardId)->decrement('card_balance', $amount);
        }

        return EzkardTransaction::create([
            'merchant_id' => $merchantId,
            'ezkard_id' => $ezkardId,
            'transaction_id' => $this->nextTransactionId(),
            'amount' => $amount,
            'trans_type_id' => $transTypeId,
            'description' => $description,
            'reference_id' => $referenceId,
            'timestamp' => now(),
            'trans_status_id' => 0,
        ]);
    }

    /** Mirrors legacy's `saveCustomerTransactionHistory()`. */
    public function logCustomerHistory(?Customer $customer, ?int $ezkardAccountId, string $reference, string $type, string $category, string $description, float $amount, string $orientation): void
    {
        if (! $customer) {
            return;
        }

        CustomerTransactionHistory::create([
            'customer_id' => $customer->id,
            'ezkard_account_id' => $ezkardAccountId,
            'transaction_reference' => $reference,
            'transaction_type' => $type,
            'category' => $category,
            'status' => 'COMPLETED',
            'description' => $description,
            'amount' => $amount,
            'transaction_fee' => 0,
            'sending_fee' => 0,
            'vat' => 0,
            'channel' => 'AdminPanel',
            'finance_orientation' => $orientation,
            'created_date' => now(),
            'running_balance' => null,
        ]);
    }
}
