<?php

namespace App\Services\Customer;

use App\Models\ActivityLog;
use App\Models\Mysuncash\Bank;
use App\Models\Mysuncash\BusinessBillpayBank;
use App\Models\Mysuncash\Customer;
use App\Models\Mysuncash\CustomerBank;
use App\Models\Mysuncash\CustomerSettlement;
use App\Models\Mysuncash\CustomerTransactionHistory;
use App\Models\Mysuncash\EzkardTransaction;
use App\Models\Mysuncash\SystemSetting;
use App\Models\Mysuncash\UserAccount;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Customers > Bank Loads" — the admin approval queue for customers
 * DEPOSITING money into their wallet via manual bank transfer (legacy
 * `Administrator::customer_bankpayments()` and friends). Shares the same
 * `customer_settlements` table as Customer Settlements, scoped to
 * `transaction_type = 'LOAD' AND withdrawal_type = ''` — the opposite money
 * direction: nothing is debited at request time, so approving here CREDITS
 * the customer's `ezkard_accounts.card_balance` (unlike Settlements'
 * approve, which touches no balance), and rejecting touches no balance at
 * all (there was never anything to reverse), only logging a REJECTED
 * statement line for visibility.
 *
 * Legacy's admin can edit the credited "Amount" before processing (to
 * match what the bank actually confirmed was received, which may differ
 * from what the customer entered when submitting the deposit slip) — the
 * only meaningfully-required input on this whole screen; every other
 * "required" field legacy's JS checks (Payee, Check Number) has zero
 * effect on the actual write, so those aren't replicated as fake gates.
 *
 * `customer_settlements.linked_bank_branch_id` is `-1`/unmatched for ~17%
 * of live Bank Loads rows, and legacy's detail query INNER JOINs through
 * it — meaning legacy's own detail page fails to load ("no records found")
 * for those rows. This mirrors the INTENDED behavior instead: bank/account
 * fields are simply blank when there's no linked bank, rather than failing
 * the whole page. Its "View Settlements" button (`getBankPaymentTransactions`)
 * also has a legacy bug — its query isn't scoped to `transaction_type`, so
 * it would show a customer's Settlement withdrawal requests mislabeled as
 * "Bank Load" too; this scopes it to the customer's own other Bank Loads,
 * matching what the button is actually for.
 *
 * Deliberately NOT ported: the SMS/push/e-mail notifications sent on every
 * action (same reasoning as every other notification-sending feature this
 * session), and the Christmas-promo raffle-ticket entries created on
 * approve (a time-limited seasonal marketing feature, not core to this
 * review queue).
 */
class CustomerBankLoadService
{
    private const STATUSES = ['pending' => CustomerSettlement::STATUS_PENDING, 'approved' => CustomerSettlement::STATUS_PROCESSED, 'rejected' => CustomerSettlement::STATUS_REJECTED];

    private const PAN_KEK = 'cfbe176207b80774e8911c10893f5a0f';

    private ?string $panKeyCache = null;

    private function hideItDecrypt(string $pass, string $encrypted, string $iv): ?string
    {
        $result = openssl_decrypt($encrypted, 'aes-256-cbc', $pass, false, substr(sha1($iv), 3, 16));

        return $result === false ? null : $result;
    }

    private function panKey(): string
    {
        if ($this->panKeyCache !== null) {
            return $this->panKeyCache;
        }

        $dekEnc = DB::connection('mysuncash')->table('keys')->orderByDesc('timestamp')->value('key');
        $this->panKeyCache = $dekEnc ? (string) $this->hideItDecrypt(self::PAN_KEK, $dekEnc, sha1('aes-256-cbc')) : '';

        return $this->panKeyCache;
    }

    private function decryptPan(?string $encrypted, string $ivSeed): ?string
    {
        if (! filled($encrypted)) {
            return null;
        }

        return $this->hideItDecrypt($this->panKey(), $encrypted, sha1(md5($ivSeed))) ?: null;
    }

    private function resolveImage(?string $value): ?string
    {
        if (! filled($value)) {
            return null;
        }
        if (str_starts_with($value, 'http') || str_starts_with($value, 'data:image/')) {
            return $value;
        }

        return 'data:image/jpeg;base64,'.$value;
    }

    /**
     * Legacy's due-date branch is `if ($row->wtype = 'STANDARD' ...)` — an
     * assignment, not a comparison, so it's always truthy and every Bank
     * Loads row is treated as "standard" timing regardless of anything
     * else. Mirrored here as simply always using the standard setting.
     */
    private function dueDate(CustomerSettlement $settlement): ?string
    {
        if ($settlement->status !== CustomerSettlement::STATUS_PENDING) {
            return null;
        }

        $days = (int) (SystemSetting::where('set_code', 'customer_withdrawal_due_standard')->value('set_value') ?? 3);
        $due = Carbon::parse($settlement->created_date)->addDays($days);

        return $due->isPast() ? 'OverDue' : $due->toDateTimeString();
    }

    private function mapListRow(CustomerSettlement $settlement): array
    {
        return [
            'id' => $settlement->id,
            'transaction_id' => sprintf('%08d', $settlement->id),
            'customer_name' => trim((string) $settlement->customer?->first_name.' '.(string) $settlement->customer?->last_name) ?: '—',
            'amount' => (float) $settlement->amount,
            'due_date' => $this->dueDate($settlement),
            'status' => $settlement->status,
            'created_date' => $settlement->created_date,
            'updated_date' => $settlement->updated_date,
            'updated_by_user' => $settlement->updated_by ? UserAccount::where('id', $settlement->updated_by)->value('user_name') : null,
        ];
    }

    private function baseQuery()
    {
        return CustomerSettlement::with('customer')
            ->where('withdrawal_type', '')
            ->where('transaction_type', 'LOAD');
    }

    public function list(): array
    {
        $result = [];
        foreach (self::STATUSES as $key => $status) {
            $result[$key] = $this->baseQuery()
                ->where('status', $status)
                ->orderByDesc('created_date')
                ->get()
                ->map(fn (CustomerSettlement $s) => $this->mapListRow($s))
                ->all();
        }

        return $result;
    }

    /**
     * @throws ValidationException
     */
    private function findOrFail(int $id): CustomerSettlement
    {
        $settlement = $this->baseQuery()->find($id);
        if (! $settlement) {
            throw ValidationException::withMessages(['id' => ['Bank load request not found.']]);
        }

        return $settlement;
    }

    /**
     * @throws ValidationException
     */
    public function getDetail(int $id): array
    {
        $settlement = $this->findOrFail($id);
        $customer = $settlement->customer;

        $bank = null;
        if ($settlement->linked_bank_branch_id && (string) $settlement->linked_bank_branch_id !== '-1') {
            $bank = CustomerBank::with('businessBillpayBank.bank')->find($settlement->linked_bank_branch_id);
        }

        $history = CustomerSettlement::where('customer_id', $customer->id)
            ->orderByDesc('created_date')
            ->get(['created_date', 'amount']);
        $weeks = max(1, $history->isNotEmpty() ? Carbon::parse($history->last()->created_date)->diffInWeeks(Carbon::parse($history->first()->created_date)) : 1);

        $processedHistory = CustomerSettlement::where('customer_id', $customer->id)
            ->where('status', CustomerSettlement::STATUS_PROCESSED)
            ->get(['created_date', 'amount']);
        $processedWeeks = max(1, $processedHistory->isNotEmpty() ? Carbon::parse($processedHistory->last()->created_date)->diffInWeeks(Carbon::parse($processedHistory->first()->created_date)) : 1);

        return [
            'id' => $settlement->id,
            'transaction_id' => sprintf('%08d', $settlement->id),
            'status' => $settlement->status,
            'customer_id' => $customer->id,
            'amount' => (float) $settlement->amount,
            'bank' => $bank?->businessBillpayBank?->bank?->name,
            'branch' => $bank?->businessBillpayBank?->branch_info,
            'account_number' => $bank ? $this->decryptPan($bank->account_number, (string) $customer->id) : null,
            'bank_deposit_to' => $settlement->bank_deposit_to,
            'deposit_slip_url' => $this->resolveImage($settlement->deposit_slip),
            'customer_name' => trim((string) $customer->first_name.' '.(string) $customer->last_name) ?: '—',
            'customer_mobile' => $customer->mobile,
            'email' => $customer->email,
            'risk_rating' => $customer->risk_rating,
            'transaction_reference_id' => $settlement->transaction_reference_id,
            'message' => $settlement->message,
            'created_date' => $settlement->created_date,
            'created_by' => $settlement->created_by,
            'updated_date' => $settlement->updated_date,
            'updated_by' => $settlement->updated_by,
            'last_withdrawal_date' => $history->first()?->created_date,
            'last_withdrawal_amount' => (float) ($history->first()?->amount ?? 0),
            'average_weekly_withdrawal_amount' => round((float) $history->sum('amount') / $weeks, 2),
            'average_weekly_withdrawal_frequency' => round($history->count() / $weeks, 2),
            'average_weekly_transaction_credits' => round((float) $processedHistory->sum('amount') / $processedWeeks, 2),
            'average_weekly_transaction_count' => round($processedHistory->count() / $processedWeeks, 2),
        ];
    }

    /**
     * "View Settlements" — this same customer's other Bank Loads (legacy's
     * getBankPaymentTransactions(), scoped to LOAD rows only — see class
     * docblock for why that scoping is a deliberate correction).
     */
    public function history(int $id): array
    {
        $settlement = $this->findOrFail($id);

        return $this->baseQuery()
            ->where('customer_id', $settlement->customer_id)
            ->orderByDesc('created_date')
            ->get()
            ->map(fn (CustomerSettlement $s) => $this->mapListRow($s))
            ->all();
    }

    /**
     * "View Transactions" — the customer's card-balance ledger (legacy's
     * tools_model::get_cardholder_transactions(), limited to the latest 10).
     */
    public function transactionHistory(int $id): array
    {
        $settlement = $this->findOrFail($id);
        $customer = $settlement->customer;
        if (! $customer?->ezkard_account_id) {
            return [];
        }

        return EzkardTransaction::with('transactionType')
            ->where('ezkard_id', $customer->ezkard_account_id)
            ->orderByDesc('timestamp')
            ->limit(10)
            ->get()
            ->map(function (EzkardTransaction $transaction) {
                $direction = $transaction->transactionType?->direction;
                $isCredit = $direction !== null && (int) $direction === 0;

                return [
                    'timestamp' => $transaction->timestamp,
                    'transaction_id' => $transaction->transaction_id,
                    'description' => $transaction->description,
                    'debit' => $isCredit ? 0.0 : (float) $transaction->amount,
                    'credit' => $isCredit ? (float) $transaction->amount : 0.0,
                    'balance' => (float) $transaction->running_balance,
                ];
            })
            ->all();
    }

    /**
     * @throws ValidationException
     */
    public function approve(int $id, array $data, string $actorId): array
    {
        $settlement = $this->findOrFail($id);
        if ($settlement->status !== CustomerSettlement::STATUS_PENDING) {
            throw ValidationException::withMessages(['status' => ['This request has already been processed.']]);
        }

        $amount = (float) str_replace(',', '', (string) ($data['amount'] ?? $settlement->amount));
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => ['Enter a valid amount.']]);
        }

        $customer = $settlement->customer;
        $referenceId = trim((string) ($data['transaction_reference_id'] ?? ''));

        DB::connection('mysuncash')->transaction(function () use ($settlement, $customer, $amount, $referenceId, $actorId) {
            $transactionId = (string) time();
            while (DB::connection('mysuncash')->table('ezkard_transactions')->where('transaction_id', $transactionId)->exists()) {
                $transactionId = (string) (((int) $transactionId) + 1);
            }

            $settlement->update([
                'transaction_reference_id' => $referenceId ?: null,
                'transaction_id' => $transactionId,
                'amount' => $amount,
                'fee' => 0,
                'total_amount' => $amount,
                'status' => CustomerSettlement::STATUS_PROCESSED,
                'proccessed_date' => now(),
                'proccessed_by' => $actorId,
                'updated_date' => now(),
                'updated_by' => $actorId,
            ]);

            if ($customer?->ezkard_account_id) {
                DB::connection('mysuncash')->table('ezkard_accounts')
                    ->where('id', $customer->ezkard_account_id)
                    ->increment('card_balance', $amount);

                EzkardTransaction::create([
                    'merchant_id' => -1,
                    'ezkard_id' => $customer->ezkard_account_id,
                    'transaction_id' => $transactionId,
                    'amount' => $amount,
                    'trans_type_id' => 75,
                    'description' => "Load via Bank - Credit ({$transactionId}) ",
                    'reference_id' => $settlement->id,
                    'timestamp' => now(),
                    'trans_status_id' => 0,
                ]);

                CustomerTransactionHistory::create([
                    'customer_id' => $customer->id,
                    'ezkard_account_id' => $customer->ezkard_account_id,
                    'transaction_reference' => $transactionId,
                    'transaction_type' => 'CustomerDeposit',
                    'category' => 'REQUEST',
                    'status' => 'COMPLETED',
                    'description' => "Load via Bank - Credit ({$transactionId}) ",
                    'amount' => $amount,
                    'transaction_fee' => 0,
                    'sending_fee' => 0,
                    'vat' => 0,
                    'channel' => 'CustomerApp',
                    'finance_orientation' => 'CREDIT',
                    'created_date' => now(),
                    'running_balance' => null,
                ]);
            }
        });

        $customerName = trim((string) $customer?->first_name.' '.(string) $customer?->last_name) ?: '—';
        ActivityLog::recordAction(User::find($actorId), 'Bank Loads', 'processed', "Processed bank load of {$amount} for {$customerName}", $settlement, null);

        return ['message' => 'Customer settlement has been processed'];
    }

    /**
     * @throws ValidationException
     */
    public function reject(int $id, array $data, string $actorId): array
    {
        $settlement = $this->findOrFail($id);
        if ($settlement->status !== CustomerSettlement::STATUS_PENDING) {
            throw ValidationException::withMessages(['status' => ['This request has already been processed.']]);
        }

        $customer = $settlement->customer;
        $message = trim((string) ($data['message'] ?? ''));

        DB::connection('mysuncash')->transaction(function () use ($settlement, $customer, $message, $actorId) {
            $settlement->update([
                'updated_by' => $actorId,
                'updated_date' => now(),
                'rejected_by' => $actorId,
                'rejected_date' => now(),
                'status' => CustomerSettlement::STATUS_REJECTED,
                'message' => $message ?: null,
            ]);

            if ($customer?->ezkard_account_id) {
                $transactionId = (string) time();
                while (DB::connection('mysuncash')->table('ezkard_transactions')->where('transaction_id', $transactionId)->exists()) {
                    $transactionId = (string) (((int) $transactionId) + 1);
                }

                CustomerTransactionHistory::create([
                    'customer_id' => $customer->id,
                    'ezkard_account_id' => $customer->ezkard_account_id,
                    'transaction_reference' => $transactionId,
                    'transaction_type' => 'CustomerWithdrawal',
                    'category' => 'REQUEST',
                    'status' => 'REJECTED',
                    'description' => "Bank deposit -Log ({$transactionId}) ",
                    'amount' => (float) $settlement->amount,
                    'transaction_fee' => 0,
                    'sending_fee' => 0,
                    'vat' => 0,
                    'channel' => 'CustomerApp',
                    'finance_orientation' => 'LOG',
                    'created_date' => now(),
                    'running_balance' => null,
                ]);
            }
        });

        ActivityLog::recordAction(User::find($actorId), 'Bank Loads', 'rejected', "Rejected bank load request #{$settlement->id}", $settlement, null);

        return ['message' => 'Request has been rejected.'];
    }
}
