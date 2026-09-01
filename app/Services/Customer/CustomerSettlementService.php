<?php

namespace App\Services\Customer;

use App\Models\ActivityLog;
use App\Models\Mysuncash\Bank;
use App\Models\Mysuncash\BankAccount;
use App\Models\Mysuncash\BusinessBillpayBank;
use App\Models\Mysuncash\Customer;
use App\Models\Mysuncash\CustomerBank;
use App\Models\Mysuncash\CustomerSettlement;
use App\Models\Mysuncash\CustomerTransactionHistory;
use App\Models\Mysuncash\EzkardTransaction;
use App\Models\Mysuncash\KioskBankAccount;
use App\Models\Mysuncash\KioskManager;
use App\Models\Mysuncash\SystemSetting;
use App\Models\Mysuncash\UserAccount;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Customers > Settlements" — the admin approval queue for CUSTOMER
 * bank-withdrawal requests (legacy `Administrator::customer_settlement()`
 * and friends, `clients_model.php`'s `*customer_settlement*()` methods).
 * Requests are created by the customer app/kiosk channels (outside this
 * codebase); this side only reviews, processes, or rejects them.
 *
 * Unlike Merchant Settlements, the customer's balance is already debited
 * at REQUEST time (before this queue ever sees it) — legacy's process
 * action does not touch any balance, it only marks the row PROCESSED and
 * confirms the manual bank transfer happened outside the system. Rejecting,
 * however, DOES credit the withdrawal amount back to the customer's
 * `ezkard_accounts.card_balance`, since the payout never happened.
 *
 * `customer_banks`/`kiosk_bank_accounts` store `account_name`/`account_number`
 * (and, for Kiosk, `customer_name`) AES-encrypted, keyed by a DB-stored,
 * KEK-wrapped DEK (legacy's `HideIt` library + `tools::get_pan_key()`) — the
 * exact same scheme legacy's own admin panel already decrypts these with,
 * replicated here (see `decryptPan()`) since the account/routing number is
 * the one piece of information the admin actually needs to complete a manual
 * bank transfer; without it, "process" would be unusable.
 *
 * Deliberately NOT ported: the SMS/e-mail notifications sent on every
 * action (same reasoning as every other notification-sending feature this
 * session), and the Kiosk-channel `customer_settlements_retail` mirror
 * table update (a separate white-label retail product's own bookkeeping,
 * not present in this system).
 */
class CustomerSettlementService
{
    private const STATUSES = ['pending' => CustomerSettlement::STATUS_PENDING, 'approved' => CustomerSettlement::STATUS_PROCESSED, 'rejected' => CustomerSettlement::STATUS_REJECTED];

    private const NO_REFUND_CHANNELS = ['WEBPOS', 'KioskCommission'];

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

    private function withdrawalTypeLabel(?string $withdrawalType): string
    {
        return match (strtolower((string) $withdrawalType)) {
            'standard' => 'Standard (2-3 Days)',
            'express' => 'Express (1 Day)',
            default => (string) $withdrawalType,
        };
    }

    private function dueDate(CustomerSettlement $settlement): ?string
    {
        if ($settlement->status !== CustomerSettlement::STATUS_PENDING) {
            return null;
        }

        $wtype = strtolower((string) $settlement->withdrawal_type);
        if (! in_array($wtype, ['standard', 'express'], true)) {
            return 'OverDue';
        }

        $days = (int) (SystemSetting::where('set_code', $wtype === 'express'
            ? 'customer_withdrawal_due_express'
            : 'customer_withdrawal_due_standard')->value('set_value') ?? ($wtype === 'express' ? 1 : 3));

        $due = Carbon::parse($settlement->created_date)->addDays($days);

        return $due->isPast() ? 'OverDue' : $due->toDateTimeString();
    }

    /**
     * Resolves the customer/kiosk identity behind a settlement row —
     * mirrors legacy's channel-branching CASE WHEN in getCustomerSettlements()
     * / getCustomerSettlementInfo() into one place shared by the list and
     * detail views.
     */
    private function resolveIdentity(CustomerSettlement $settlement): array
    {
        $channel = $settlement->channel ?: 'CUSTOMERAPP';
        $customerId = $settlement->customer_id;
        $hasCustomer = $customerId !== null && (string) $customerId !== '-1';

        $firstName = null;
        $lastName = null;
        $mobile = $settlement->customer_number;
        $email = null;
        $displayName = null;

        if ($channel === 'Kiosk') {
            $byMobile = Customer::where('mobile', $settlement->customer_number)->first();
            $firstName = $byMobile?->first_name;
            $lastName = $byMobile?->last_name;

            $kioskAccount = null;
            if ($settlement->kiosk_banked_account_id && (string) $settlement->kiosk_banked_account_id !== '-1') {
                $kioskAccount = KioskBankAccount::where('id', $settlement->kiosk_banked_account_id)
                    ->where('customer_number', $settlement->customer_number)
                    ->where('user_type', 'customer')
                    ->first();
            }
            $kioskAccount ??= KioskBankAccount::where('customer_number', $settlement->customer_number)->first();

            if ($kioskAccount) {
                $displayName = $this->decryptPan($kioskAccount->customer_name, $settlement->customer_number);
            }
        } elseif ($channel === 'KioskCommission') {
            $manager = KioskManager::find($customerId);
            $firstName = $manager?->manager_firstname;
            $lastName = $manager?->manager_lastname;
            $email = $manager?->email;
        } elseif ($hasCustomer) {
            $customer = Customer::find($customerId);
            $firstName = $customer?->first_name;
            $lastName = $customer?->last_name;
            $mobile = $customer?->mobile ?: $mobile;
            $email = $customer?->email;
        } else {
            $bank = $settlement->linked_bank_branch_id ? CustomerBank::find($settlement->linked_bank_branch_id) : null;
            $firstName = $bank?->first_name;
            $lastName = $bank?->last_name;
            $mobile = $bank?->mobile ?: $mobile;
        }

        $displayName ??= trim((string) $firstName.' '.(string) $lastName) ?: ($mobile ?: '—');

        return [
            'channel' => $channel,
            'customer_id' => $customerId,
            'has_customer' => $hasCustomer,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'mobile' => $mobile,
            'email' => $email,
            'display_name' => $displayName,
        ];
    }

    /**
     * Bank/branch/account details for the detail view — mirrors legacy's
     * channel-branching CASE WHEN for bank/branch_info/account_type/
     * account_name/account_number in getCustomerSettlementInfo().
     */
    private function resolveBankDetails(CustomerSettlement $settlement, array $identity): array
    {
        $channel = $identity['channel'];

        if ($channel === 'Kiosk') {
            $kioskAccount = null;
            if ($settlement->kiosk_banked_account_id && (string) $settlement->kiosk_banked_account_id !== '-1') {
                $kioskAccount = KioskBankAccount::where('id', $settlement->kiosk_banked_account_id)
                    ->where('customer_number', $settlement->customer_number)
                    ->where('user_type', 'customer')
                    ->first();
            }
            $kioskAccount ??= KioskBankAccount::where('customer_number', $settlement->customer_number)->first();

            return [
                'bank' => $kioskAccount?->bank_name,
                'branch' => $kioskAccount?->branch_name,
                'account_type' => $kioskAccount?->account_type,
                'account_name' => $kioskAccount ? $this->decryptPan($kioskAccount->customer_name, $settlement->customer_number) : null,
                'account_number' => $kioskAccount ? $this->decryptPan($kioskAccount->account_number, $settlement->customer_number) : null,
            ];
        }

        if ($channel === 'KioskCommission') {
            $details = KioskManager::find($identity['customer_id'])?->details;

            return [
                'bank' => $details?->bank_id ? Bank::find($details->bank_id)?->name : null,
                'branch' => $details?->bank_branch_id ? BusinessBillpayBank::find($details->bank_branch_id)?->branch_info : null,
                'account_type' => $settlement->account_type,
                'account_name' => $details?->bank_account_name,
                'account_number' => $details?->bank_account_number,
            ];
        }

        $bank = $settlement->linked_bank_branch_id ? CustomerBank::with('businessBillpayBank.bank')->find($settlement->linked_bank_branch_id) : null;
        $ivSeed = (string) $identity['customer_id'];

        return [
            'bank' => $bank?->businessBillpayBank?->bank?->name,
            'branch' => $bank?->businessBillpayBank?->branch_info,
            'account_type' => $bank?->account_type,
            'account_name' => $bank ? $this->decryptPan($bank->account_name, $ivSeed) : null,
            'account_number' => $bank ? $this->decryptPan($bank->account_number, $ivSeed) : null,
        ];
    }

    private function mapListRow(CustomerSettlement $settlement): array
    {
        $identity = $this->resolveIdentity($settlement);

        return [
            'id' => $settlement->id,
            'transaction_id' => sprintf('%08d', $settlement->id),
            'customer_name' => $identity['display_name'],
            'channel' => $identity['channel'],
            'withdrawal_type' => $this->withdrawalTypeLabel($settlement->withdrawal_type),
            'amount' => (float) $settlement->amount,
            'due_date' => $this->dueDate($settlement),
            'status' => $settlement->status,
            'created_date' => $settlement->created_date,
            'updated_date' => $settlement->updated_date,
            'updated_by_user' => $settlement->updated_by ? UserAccount::where('id', $settlement->updated_by)->value('user_name') : null,
        ];
    }

    public function list(): array
    {
        $result = [];
        foreach (self::STATUSES as $key => $status) {
            $result[$key] = CustomerSettlement::where('withdrawal_type', '!=', '')
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
        $settlement = CustomerSettlement::find($id);
        if (! $settlement) {
            throw ValidationException::withMessages(['id' => ['Settlement request not found.']]);
        }

        return $settlement;
    }

    /**
     * The averaging window/condition legacy scopes by: real customers use
     * customer_id, channel-only rows (Kiosk/KioskCommission/Paymysuncash) use
     * customer_number instead.
     */
    private function averageScope(CustomerSettlement $settlement, array $identity)
    {
        $query = CustomerSettlement::query();
        if (in_array($identity['channel'], ['Kiosk', 'KioskCommission', 'Paymysuncash'], true)) {
            return $query->where('customer_number', $settlement->customer_number);
        }

        return $query->where('customer_id', $identity['customer_id']);
    }

    /**
     * @throws ValidationException
     */
    public function getDetail(int $id): array
    {
        $settlement = $this->findOrFail($id);
        $identity = $this->resolveIdentity($settlement);
        $bankDetails = $this->resolveBankDetails($settlement, $identity);

        $riskRating = in_array($identity['channel'], ['Kiosk', 'KioskCommission'], true)
            ? 'low'
            : (Customer::find($identity['customer_id'])?->risk_rating ?? '—');

        $history = $this->averageScope($settlement, $identity)
            ->whereIn('status', [CustomerSettlement::STATUS_PENDING, CustomerSettlement::STATUS_PROCESSED, CustomerSettlement::STATUS_REJECTED])
            ->orderByDesc('created_date')
            ->get(['created_date', 'amount']);

        $weeks = max(1, $history->isNotEmpty() ? Carbon::parse($history->last()->created_date)->diffInWeeks(Carbon::parse($history->first()->created_date)) : 1);
        $processedHistory = $history->isEmpty() ? collect() : $this->averageScope($settlement, $identity)
            ->where('status', CustomerSettlement::STATUS_PROCESSED)
            ->get(['created_date', 'amount']);
        $processedWeeks = max(1, $processedHistory->isNotEmpty() ? Carbon::parse($processedHistory->last()->created_date)->diffInWeeks(Carbon::parse($processedHistory->first()->created_date)) : 1);

        return [
            'id' => $settlement->id,
            'transaction_id' => sprintf('%08d', $settlement->id),
            'status' => $settlement->status,
            'channel' => $identity['channel'],
            'customer_id' => $identity['has_customer'] ? $identity['customer_id'] : null,
            'withdrawal_type' => $this->withdrawalTypeLabel($settlement->withdrawal_type),
            'amount' => (float) $settlement->amount,
            'fee' => (float) $settlement->fee,
            'total_amount' => (float) $settlement->total_amount,
            'bank' => $bankDetails['bank'],
            'branch' => $bankDetails['branch'],
            'account_type' => $bankDetails['account_type'],
            'account_name' => $bankDetails['account_name'],
            'account_number' => $bankDetails['account_number'],
            'customer_name' => $identity['display_name'],
            'customer_mobile' => $identity['mobile'],
            'email' => $identity['email'],
            'risk_rating' => $riskRating,
            'check_number' => $settlement->check_number,
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
     * "View Settlements" — this same customer's other settlement requests
     * over the last 2 months (legacy's get_customer_settlement_transaction()).
     */
    public function history(int $id): array
    {
        $settlement = $this->findOrFail($id);
        $identity = $this->resolveIdentity($settlement);

        return $this->averageScope($settlement, $identity)
            ->where('created_date', '>=', now()->subMonths(2))
            ->where('withdrawal_type', '!=', '')
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
        $identity = $this->resolveIdentity($settlement);
        if (! $identity['has_customer']) {
            return [];
        }

        $customer = Customer::find($identity['customer_id']);
        if (! $customer || ! $customer->ezkard_account_id) {
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

    public function listLinkedBankAccounts(): array
    {
        return BankAccount::with('businessBillpayBank')
            ->where('status', 'A')
            ->orderByDesc('id')
            ->get()
            ->map(fn (BankAccount $account) => [
                'id' => $account->id,
                'account_name' => $account->account_name,
                'account_no' => $account->account_no,
                'bank' => $account->businessBillpayBank?->banks,
                'branch' => $account->businessBillpayBank?->branch_info,
            ])
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

        $identity = $this->resolveIdentity($settlement);
        $referenceId = trim((string) ($data['transaction_reference_id'] ?? ''));

        DB::connection('mysuncash')->transaction(function () use ($settlement, $identity, $referenceId, $actorId) {
            $transactionId = (string) time();
            while (DB::connection('mysuncash')->table('ezkard_transactions')->where('transaction_id', $transactionId)->exists()) {
                $transactionId = (string) (((int) $transactionId) + 1);
            }

            $settlement->update([
                'transaction_reference_id' => $referenceId ?: null,
                'transaction_id' => $transactionId,
                'status' => CustomerSettlement::STATUS_PROCESSED,
                'proccessed_date' => now(),
                'proccessed_by' => $actorId,
                'updated_date' => now(),
                'updated_by' => $actorId,
            ]);

            if ($identity['channel'] === 'CUSTOMERAPP' && $identity['has_customer']) {
                $customer = Customer::find($identity['customer_id']);
                if ($customer?->ezkard_account_id) {
                    if ($settlement->linked_bank_branch_id) {
                        $bank = CustomerBank::find($settlement->linked_bank_branch_id);
                        if ($bank && $bank->card_status === 'UNVERIFIED') {
                            $bank->update(['card_status' => 'VERIFIED', 'update_date' => now()]);
                        }
                    }

                    CustomerTransactionHistory::create([
                        'customer_id' => $customer->id,
                        'ezkard_account_id' => $customer->ezkard_account_id,
                        'transaction_reference' => $transactionId,
                        'transaction_type' => 'CustomerWithdrawal',
                        'category' => 'REQUEST',
                        'status' => 'COMPLETED',
                        'description' => "Bank withdrawal -Debit ({$transactionId}) ",
                        'amount' => (float) $settlement->amount,
                        'transaction_fee' => (float) $settlement->fee,
                        'sending_fee' => 0,
                        'vat' => 0,
                        'channel' => 'CustomerApp',
                        'finance_orientation' => 'DEBIT',
                        'created_date' => now(),
                        'running_balance' => null,
                    ]);
                }
            }
        });

        ActivityLog::recordAction(User::find($actorId), 'Customer Settlements', 'approved', 'Approved settlement request #'.sprintf('%08d', $settlement->id).' for '.number_format((float) $settlement->amount, 2), $settlement, null);

        return ['message' => 'Transaction has been process successfully.'];
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

        $identity = $this->resolveIdentity($settlement);
        $message = trim((string) ($data['message'] ?? ''));

        DB::connection('mysuncash')->transaction(function () use ($settlement, $identity, $message, $actorId) {
            $settlement->update([
                'updated_by' => $actorId,
                'updated_date' => now(),
                'rejected_by' => $actorId,
                'rejected_date' => now(),
                'status' => CustomerSettlement::STATUS_REJECTED,
                'message' => $message ?: null,
            ]);

            if ($identity['has_customer'] && ! in_array($identity['channel'], self::NO_REFUND_CHANNELS, true)) {
                $customer = Customer::find($identity['customer_id']);
                if ($customer?->ezkard_account_id) {
                    DB::connection('mysuncash')->table('ezkard_accounts')
                        ->where('id', $customer->ezkard_account_id)
                        ->increment('card_balance', (float) $settlement->total_amount);

                    $transactionId = (string) time();
                    while (DB::connection('mysuncash')->table('ezkard_transactions')->where('transaction_id', $transactionId)->exists()) {
                        $transactionId = (string) (((int) $transactionId) + 1);
                    }

                    EzkardTransaction::create([
                        'merchant_id' => -1,
                        'ezkard_id' => $customer->ezkard_account_id,
                        'transaction_id' => $transactionId,
                        'amount' => (float) $settlement->total_amount,
                        'trans_type_id' => 75,
                        'description' => "Bank withdrawal -Credit ({$transactionId}) ",
                        'reference_id' => $settlement->id,
                        'timestamp' => now(),
                        'trans_status_id' => 0,
                    ]);

                    CustomerTransactionHistory::create([
                        'customer_id' => $customer->id,
                        'ezkard_account_id' => $customer->ezkard_account_id,
                        'transaction_reference' => $transactionId,
                        'transaction_type' => 'CustomerWithdrawal',
                        'category' => 'REQUEST',
                        'status' => 'REJECTED',
                        'description' => "Bank withdrawal -Credit ({$transactionId}) ",
                        'amount' => (float) $settlement->amount,
                        'transaction_fee' => (float) $settlement->fee,
                        'sending_fee' => 0,
                        'vat' => 0,
                        'channel' => 'CustomerApp',
                        'finance_orientation' => 'CREDIT',
                        'created_date' => now(),
                        'running_balance' => null,
                    ]);
                }
            }
        });

        ActivityLog::recordAction(User::find($actorId), 'Customer Settlements', 'rejected', 'Rejected settlement request #'.sprintf('%08d', $settlement->id).($message !== '' ? ": {$message}" : ''), $settlement, null);

        return ['message' => 'Transaction has been rejected successfully.'];
    }
}
