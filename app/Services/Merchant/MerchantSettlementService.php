<?php

namespace App\Services\Merchant;

use App\Mail\MerchantSettlementDecisionMail;
use App\Models\Mysuncash\BankAccount;
use App\Models\Mysuncash\BusinessBillpayBank;
use App\Models\Mysuncash\ClientTransaction;
use App\Models\Mysuncash\ClientTransactionDetail;
use App\Models\Mysuncash\ManualSettlement;
use App\Models\Mysuncash\Merchant;
use App\Models\Mysuncash\MerchantBankAccount;
use App\Models\Mysuncash\MerchantTransactionHistory;
use App\Models\Mysuncash\SystemSetting;
use App\Models\Mysuncash\UserAccount;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * "Merchant Settlements" — the admin approval queue for merchant withdrawal
 * requests (legacy `Administrator::manual_settlement()` and friends).
 * Requests themselves are created by the merchant portal (outside this
 * codebase); this side only reviews, approves, or rejects them.
 *
 * Approving debits `clients.client_prefund` by the request's amount + fee
 * and writes the same ledger trail legacy does (client_transactions,
 * client_transaction_details, merchant_transaction_history), then e-mails
 * the merchant. No external bank/payment API is involved — the actual
 * disbursement happens outside the system; approving here just records
 * that it happened.
 *
 * Deliberately NOT ported: the `kiosk`/`paymysuncash` channel-specific
 * reject side effects (reversing a fee against a hardcoded partner client
 * id, reactivating a voucher) — those belong to separate white-label
 * products not present in this system. Rejecting a request from either of
 * those channels still works here, just without those extra reversals.
 */
class MerchantSettlementService
{
    private const STATUSES = [
        'pending' => ManualSettlement::STATUS_PENDING,
        'approved' => ManualSettlement::STATUS_APPROVED,
        'rejected' => ManualSettlement::STATUS_REJECTED,
    ];

    private function withdrawalTypeLabel(?string $withdrawalType): string
    {
        return $withdrawalType === 'WIF' ? 'Express (1 Day)' : 'Standard (2-3 Days)';
    }

    private function dueDate(ManualSettlement $settlement): ?string
    {
        if ($settlement->status !== ManualSettlement::STATUS_PENDING) {
            return null;
        }

        $days = (int) (SystemSetting::where('set_code', $settlement->withdrawal_type === 'WIF'
            ? 'customer_withdrawal_due_express'
            : 'customer_withdrawal_due_standard')->value('set_value') ?? ($settlement->withdrawal_type === 'WIF' ? 1 : 3));

        $due = Carbon::parse($settlement->created_date)->addDays($days);

        return $due->isPast() ? 'OverDue' : $due->toDateTimeString();
    }

    private function mapListRow(ManualSettlement $settlement): array
    {
        return [
            'id' => $settlement->id,
            'transaction_id' => sprintf('%08d', $settlement->id),
            'client_record_id' => $settlement->client_record_id,
            'suntag_shortcode' => $settlement->merchant?->suntag_shortcode,
            'dba_name' => $settlement->merchant?->dba_name,
            'type' => $settlement->type,
            'withdrawal_type' => $settlement->withdrawal_type,
            'w_type' => $this->withdrawalTypeLabel($settlement->withdrawal_type),
            'amount' => (float) $settlement->amount,
            'fee' => (float) $settlement->fee,
            'first_withdrawal' => $settlement->first_withdrawal,
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
            $result[$key] = ManualSettlement::with('merchant')
                ->where('status', $status)
                ->orderBy('id')
                ->get()
                ->map(fn (ManualSettlement $s) => $this->mapListRow($s))
                ->all();
        }

        return $result;
    }

    /**
     * @throws ValidationException
     */
    private function findOrFail(int $id): ManualSettlement
    {
        $settlement = ManualSettlement::with('merchant')->find($id);
        if (! $settlement) {
            throw ValidationException::withMessages(['id' => ['Settlement request not found.']]);
        }

        return $settlement;
    }

    /**
     * @throws ValidationException
     */
    public function getDetail(int $id): array
    {
        $settlement = $this->findOrFail($id);
        $merchant = $settlement->merchant;

        $accountName = null;
        if ($settlement->bank_account_id && (int) $settlement->bank_account_id !== -1) {
            $accountName = MerchantBankAccount::where('id', $settlement->bank_account_id)->value('account_name');
        } else {
            $accountName = MerchantBankAccount::where('merchant_id', $settlement->client_record_id)
                ->where('account_no', $settlement->account_number)
                ->where('bank', $settlement->bank)
                ->where('branch', $settlement->bank_branch)
                ->orderByDesc('id')
                ->value('account_name');
        }

        $history = ManualSettlement::where('client_record_id', $settlement->client_record_id)
            ->orderByDesc('created_date')
            ->get(['created_date', 'amount']);

        $weekly = ManualSettlement::where('client_record_id', $settlement->client_record_id)
            ->selectRaw('SUM(amount) / GREATEST(TIMESTAMPDIFF(WEEK, MIN(created_date), MAX(created_date)), 1) AS avg_amount')
            ->selectRaw('COUNT(amount) / GREATEST(TIMESTAMPDIFF(WEEK, MIN(created_date), MAX(created_date)), 1) AS avg_frequency')
            ->first();

        $weeklyProcessed = ManualSettlement::where('client_record_id', $settlement->client_record_id)
            ->where('status', ManualSettlement::STATUS_APPROVED)
            ->selectRaw('SUM(amount) / GREATEST(TIMESTAMPDIFF(WEEK, MIN(created_date), MAX(created_date)), 1) AS avg_credits')
            ->selectRaw('COUNT(amount) / GREATEST(TIMESTAMPDIFF(WEEK, MIN(created_date), MAX(created_date)), 1) AS avg_count')
            ->first();

        return [
            'id' => $settlement->id,
            'transaction_id' => sprintf('%08d', $settlement->id),
            'suntag_shortcode' => $merchant?->suntag_shortcode,
            'dba_name' => $merchant?->dba_name,
            'business_email_address' => $merchant?->billpayApplication?->business_email_address,
            'client_record_id' => $settlement->client_record_id,
            'type' => $settlement->type,
            'w_type' => $this->withdrawalTypeLabel($settlement->withdrawal_type),
            'account_type' => $settlement->account_type,
            'amount' => (float) $settlement->amount,
            'fee' => (float) $settlement->fee,
            'payee' => $settlement->payee,
            'payor' => $settlement->payor,
            'bank' => $settlement->bank,
            'bank_branch' => $settlement->bank_branch,
            'account_name' => $accountName,
            'account_number' => $settlement->account_number,
            'check_number' => $settlement->check_number,
            'bank_trans_id' => $settlement->bank_trans_id,
            'account_number_transfered' => $settlement->account_number_transfered,
            'business_billpay_banks_id' => $settlement->business_billpay_banks_id,
            'is_process' => (bool) $settlement->is_process,
            'destination' => $settlement->destination,
            'reference' => $settlement->reference,
            'message_to_business' => $settlement->message_to_business,
            'status' => $settlement->status,
            'status_text' => ['P' => 'Pending', 'A' => 'Processed', 'R' => 'Rejected'][$settlement->status] ?? 'Unknown',
            'created_date' => $settlement->created_date,
            'created_by' => $settlement->created_by,
            'updated_date' => $settlement->updated_date,
            'updated_by' => $settlement->updated_by,
            'last_withdrawal_date' => $history->first()?->created_date,
            'last_withdrawal_amount' => (float) ($history->first()?->amount ?? 0),
            'average_weekly_withdrawal_amount' => round((float) ($weekly?->avg_amount ?? 0), 2),
            'average_weekly_withdrawal_frequency' => round((float) ($weekly?->avg_frequency ?? 0), 2),
            'average_weekly_transaction_credits' => round((float) ($weeklyProcessed?->avg_credits ?? 0), 2),
            'average_weekly_transaction_count' => round((float) ($weeklyProcessed?->avg_count ?? 0), 2),
        ];
    }

    public function history(int $merchantId): array
    {
        return ManualSettlement::where('client_record_id', $merchantId)
            ->orderByDesc('created_date')
            ->get()
            ->map(fn (ManualSettlement $s) => [
                'id' => $s->id,
                'transaction_id' => sprintf('%08d', $s->id),
                'type' => $s->type,
                'w_type' => $this->withdrawalTypeLabel($s->withdrawal_type),
                'amount' => (float) $s->amount,
                'status' => $s->status,
                'status_text' => ['P' => 'Pending', 'A' => 'Processed', 'R' => 'Rejected'][$s->status] ?? 'Unknown',
                'created_date' => $s->created_date,
            ])
            ->all();
    }

    /**
     * The merchant's general ledger history (not settlement-specific) —
     * legacy's "View Transactions" button, `view_merchant_transaction()`.
     */
    public function transactionHistory(int $merchantId): array
    {
        return ClientTransaction::with('transactionType')
            ->where('client_record_id', $merchantId)
            ->orderBy('timestamp')
            ->limit(100000)
            ->get()
            ->map(fn (ClientTransaction $transaction) => [
                'timestamp' => $transaction->timestamp,
                'transaction_type' => $transaction->transactionType?->type,
                'description' => $transaction->description,
                'amount' => (float) $transaction->amount,
            ])
            ->all();
    }

    public function listBanks(): array
    {
        return BusinessBillpayBank::where('status', 'A')
            ->orderBy('banks')
            ->get(['id', 'banks', 'branch_info'])
            ->toArray();
    }

    public function listLinkedBankAccounts(): array
    {
        return BankAccount::with('businessBillpayBank')
            ->where('status', 'A')
            ->orderByDesc('id')
            ->get()
            ->map(fn (BankAccount $account) => [
                'id' => $account->id,
                'business_billpay_banks_id' => $account->business_billpay_banks_id,
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
    public function linkBankAccount(array $data): array
    {
        $bankId = (int) ($data['bank_id'] ?? 0);
        $accountName = trim((string) ($data['account_name'] ?? ''));
        $accountNo = trim((string) ($data['account_no'] ?? ''));

        $errors = [];
        if (! $bankId || ! BusinessBillpayBank::where('id', $bankId)->where('status', 'A')->exists()) {
            $errors['bank_id'] = ['Select a valid bank.'];
        }
        if ($accountName === '') {
            $errors['account_name'] = ['Account name is required.'];
        }
        if ($accountNo === '') {
            $errors['account_no'] = ['Account number is required.'];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        BankAccount::create([
            'business_billpay_banks_id' => $bankId,
            'account_name' => $accountName,
            'account_no' => $accountNo,
            'status' => 'A',
        ]);

        return $this->listLinkedBankAccounts();
    }

    /**
     * @throws ValidationException
     */
    public function approve(int $id, array $data, string $actorId): array
    {
        $settlement = $this->findOrFail($id);
        if ($settlement->status !== ManualSettlement::STATUS_PENDING) {
            throw ValidationException::withMessages(['status' => ['This request has already been processed.']]);
        }

        $merchant = $settlement->merchant;
        if (! $merchant) {
            throw ValidationException::withMessages(['id' => ['Merchant not found.']]);
        }

        $faceAmount = (float) $settlement->amount;
        $fee = (float) $settlement->fee;
        $total = $faceAmount + $fee;

        if ((float) $merchant->client_prefund < $total) {
            throw ValidationException::withMessages(['amount' => ['Insufficient balance.']]);
        }

        $message = trim((string) ($data['message'] ?? ''));
        $payee = trim((string) ($data['payee'] ?? ''));
        $type = $settlement->type;

        $updateValues = [
            'updated_by' => $actorId,
            'updated_date' => now(),
            'approved_by' => $actorId,
            'approved_date' => now(),
            'status' => ManualSettlement::STATUS_APPROVED,
            'message_to_business' => $message,
            'payee' => $payee,
        ];

        if ($type === 'Cheque') {
            $bankAccountId = (int) ($data['bank_account_id'] ?? 0);
            $checkNumber = trim((string) ($data['check_number'] ?? ''));
            $signed = (bool) ($data['is_process'] ?? false);

            $errors = [];
            $bankAccount = BankAccount::with('businessBillpayBank')->find($bankAccountId);
            if (! $bankAccount) {
                $errors['bank_account_id'] = ['Select a bank account.'];
            }
            if ($checkNumber === '') {
                $errors['check_number'] = ['Check number is required.'];
            }
            if ($payee === '') {
                $errors['payee'] = ['Payee is required.'];
            }
            if (! $signed) {
                $errors['is_process'] = ['Confirm the check has been signed.'];
            }
            if ($errors) {
                throw ValidationException::withMessages($errors);
            }

            $updateValues += [
                'check_number' => $checkNumber,
                'bank_branch' => $bankAccount->businessBillpayBank?->branch_info ?? '',
                'bank' => $bankAccount->businessBillpayBank?->banks ?? '',
                'is_process' => 1,
                'business_billpay_banks_id' => $bankAccount->business_billpay_banks_id,
                'bank_account_id' => $bankAccount->id,
            ];
        } elseif (in_array($type, ['Bank Transfer', 'Bank Deposit'], true)) {
            $bankAccountId = (int) ($data['bank_account_id'] ?? 0);
            $bankTransId = trim((string) ($data['bank_trans_id'] ?? ''));
            $accountNumber = trim((string) ($data['account_number'] ?? ''));

            $errors = [];
            $bankAccount = BankAccount::with('businessBillpayBank')->find($bankAccountId);
            if (! $bankAccount) {
                $errors['bank_account_id'] = ['Select the bank account the funds were transferred from.'];
            }
            if ($type === 'Bank Transfer' && $bankTransId === '') {
                $errors['bank_trans_id'] = ['Transaction ID is required.'];
            }
            if ($errors) {
                throw ValidationException::withMessages($errors);
            }

            $updateValues += [
                'business_billpay_banks_id' => $bankAccount->business_billpay_banks_id,
                'bank_account_id' => $bankAccount->id,
                'bank_trans_id' => $bankTransId,
                'account_number_transfered' => $accountNumber,
            ];
        }

        DB::connection('mysuncash')->transaction(function () use ($settlement, $updateValues, $merchant, $faceAmount, $fee, $type) {
            $settlement->update($updateValues);

            $this->adjustPrefund($merchant, $faceAmount, $settlement->id, $type === 'Bank Deposit' ? "Deposit ({$type})-Debit " : "Withdrawal ({$type})-Debit");
            $this->logHistory($merchant, 'Settlement', $faceAmount, $settlement->id, $type);

            $this->adjustPrefund($merchant, $fee, $settlement->id, $type === 'Bank Deposit' ? "Deposit ({$type})-Debit " : "Withdrawal Fee ({$type})-Debit");
            $this->logHistory($merchant, 'Settlement - Fee', $fee, $settlement->id, $type);
        });

        $this->sendDecisionEmail($merchant, true, $message);

        return ['message' => 'Request has been approved.'];
    }

    /**
     * @throws ValidationException
     */
    public function reject(int $id, array $data, string $actorId): array
    {
        $settlement = $this->findOrFail($id);
        if ($settlement->status !== ManualSettlement::STATUS_PENDING) {
            throw ValidationException::withMessages(['status' => ['This request has already been processed.']]);
        }

        $merchant = $settlement->merchant;
        $message = trim((string) ($data['message'] ?? ''));
        $payee = trim((string) ($data['payee'] ?? ''));

        if ($payee === '') {
            throw ValidationException::withMessages(['payee' => ['Payee is required.']]);
        }

        $settlement->update([
            'updated_by' => $actorId,
            'updated_date' => now(),
            'rejected_by' => $actorId,
            'rejected_date' => now(),
            'status' => ManualSettlement::STATUS_REJECTED,
            'message_to_business' => $message,
            'payee' => $payee,
        ]);

        if ($merchant) {
            $this->sendDecisionEmail($merchant, false, $message);
        }

        return ['message' => 'Request has been rejected.'];
    }

    private function adjustPrefund(Merchant $merchant, float $amount, int $refTransId, string $description): void
    {
        $merchant->decrement('client_prefund', $amount);
        $merchant->refresh();

        $transaction = ClientTransaction::create([
            'client_record_id' => $merchant->id,
            'user_type_id' => 2,
            'ref_trans_id' => $refTransId,
            'trans_type_id' => 5,
            'amount' => $amount,
            'description' => $description,
            'timestamp' => now(),
            'is_merchant' => 1,
            'merchant_id' => $merchant->id,
            'running_balance' => $merchant->client_prefund,
            'available_balance' => $merchant->client_prefund,
        ]);

        ClientTransactionDetail::create([
            'client_transaction_id' => $transaction->id,
            'client_account_type' => ClientTransactionDetail::ACCOUNT_TYPE_PREFUND,
            'client_record_id' => $merchant->id,
            'amount' => $amount,
        ]);
    }

    private function logHistory(Merchant $merchant, string $description, float $amount, int $reference, string $destination): void
    {
        MerchantTransactionHistory::create([
            'client_record_id' => $merchant->id,
            'system_services_code' => 'SETTLEMENT',
            'type' => 'DEBIT',
            'source' => '',
            'destination' => $destination,
            'description' => $description,
            'amount' => $amount,
            'reference' => $reference,
            'running_balance' => $merchant->client_prefund,
        ]);
    }

    /**
     * The approval/rejection itself has already been committed by the time this
     * runs — a mail delivery problem shouldn't turn a successful decision into
     * an API error, so failures here are logged and swallowed.
     */
    private function sendDecisionEmail(Merchant $merchant, bool $approved, string $message): void
    {
        $email = $merchant->merchantDetail?->contactemail;
        if (! filled($email)) {
            return;
        }

        try {
            Mail::to($email)->send(new MerchantSettlementDecisionMail($merchant->dba_name ?? '', $approved, $message));
        } catch (\Throwable $exception) {
            Log::warning('Failed to send settlement decision e-mail', ['merchant_id' => $merchant->id, 'error' => $exception->getMessage()]);
        }
    }
}
