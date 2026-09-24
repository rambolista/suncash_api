<?php

namespace App\Services\Tools;

use App\Models\ActivityLog;
use App\Models\Mysuncash\AdminManualTransaction;
use App\Models\Mysuncash\AdminTransactionType;
use App\Models\Mysuncash\Customer;
use App\Models\User;
use App\Services\Transactions\Support\LedgerAdjuster;
use Illuminate\Validation\ValidationException;

/**
 * "Tools > Customer Debit/Credit" (legacy `tools/debit_credit` view +
 * `tools_model::get_admin_trans_type()`/`get_process_admin_transaction()`).
 * Search, detail and transaction-history reuse `CustomerManagementService`
 * and `CustomerArchiveService` as-is (same customer lookup + the same
 * `ezkard_transactions` ledger already used — and already indexed — there);
 * this class only covers the two things unique to this screen: the
 * Credit/Debit transaction-type dropdown and processing the adjustment
 * itself, via the same `LedgerAdjuster` primitives Void Transaction uses.
 *
 * Legacy transaction-type ids 111 ("Customer Credited by Admin") / 112
 * ("Customer Debited by Admin") on `transaction_types` are what every admin
 * credit/debit has always been logged under on `ezkard_transactions` —
 * hardcoded here exactly as legacy hardcodes them, not the
 * `admin_transaction_types.id` the user picks (that one is only a
 * human-readable reason code, stored separately on `admin_manual_transactions`).
 */
class CustomerDebitCreditService
{
    private const CREDIT_TRANS_TYPE_ID = 111;

    private const DEBIT_TRANS_TYPE_ID = 112;

    public function __construct(private readonly LedgerAdjuster $ledger) {}

    public function transactionTypes(string $orientation): array
    {
        return AdminTransactionType::where('finance_orientation', $orientation)
            ->get(['id', 'transaction_type_description'])
            ->all();
    }

    /** @throws ValidationException */
    public function process(int $customerId, int $transTypeId, float $amount, ?string $notes, User $actor): array
    {
        $type = AdminTransactionType::find($transTypeId);
        if (! $type) {
            throw ValidationException::withMessages(['id_trans_type' => ['Transaction type not found.']]);
        }

        $customer = Customer::with('ezkardAccount')->find($customerId);
        if (! $customer || ! $customer->ezkardAccount) {
            throw ValidationException::withMessages(['id' => ['Customer or linked card account not found.']]);
        }

        $isCredit = $type->finance_orientation === 'Credit';

        $transaction = $this->ledger->adjustCardBalance(
            $customer->ezkard_account_id,
            $isCredit ? 'add' : 'less',
            $amount,
            $isCredit ? self::CREDIT_TRANS_TYPE_ID : self::DEBIT_TRANS_TYPE_ID,
            $type->transaction_type_description,
        );

        $this->ledger->logCustomerHistory(
            $customer,
            $customer->ezkard_account_id,
            $transaction->transaction_id,
            $isCredit ? 'ADMIN_CREDIT' : 'ADMIN_DEBIT',
            $isCredit ? 'Admin Credit' : 'Admin Debit',
            $type->transaction_type_description,
            $amount,
            $isCredit ? 'CREDIT' : 'DEBIT',
        );

        AdminManualTransaction::create([
            'customer_id' => $customerId,
            'admin_user_id' => $actor->id,
            'transaction_id' => $this->ledger->nextTransactionId(),
            'reference_id' => $transaction->transaction_id,
            'trans_type_id' => $transTypeId,
            'amount' => $amount,
            'notes' => filled($notes) ? $notes : null,
            'transaction_date' => now(),
        ]);

        $customer->ezkardAccount->refresh();
        $name = trim((string) $customer->first_name.' '.(string) $customer->last_name) ?: (string) $customerId;

        ActivityLog::recordAction(
            $actor,
            'Tools - Customer Debit/Credit',
            $isCredit ? 'credited' : 'debited',
            sprintf('%s %s to %s (%s)', $isCredit ? 'Credited' : 'Debited', number_format($amount, 2), $name, $type->transaction_type_description),
            $customer,
        );

        return [
            'message' => 'Successfully processed transaction.',
            'card_balance' => (float) $customer->ezkardAccount->card_balance,
        ];
    }
}
