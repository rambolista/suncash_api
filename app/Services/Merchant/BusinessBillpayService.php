<?php

namespace App\Services\Merchant;

use App\Models\ActivityLog;
use App\Models\Mysuncash\BusinessBillTransaction;
use App\Models\Mysuncash\ClientTransaction;
use App\Models\Mysuncash\ClientTransactionDetail;
use App\Models\Mysuncash\Customer;
use App\Models\Mysuncash\CustomerTransactionHistory;
use App\Models\Mysuncash\EzkardAccount;
use App\Models\Mysuncash\EzkardTransaction;
use App\Models\Mysuncash\Merchant;
use App\Models\Mysuncash\MerchantTransactionHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Business Billpay" — the admin approval queue for merchant-to-merchant
 * (and merchant-to-customer) payment requests (legacy
 * `Administrator::business_billpay()` and friends). Requests are created by
 * the Business Portal (outside this codebase); this side only reviews,
 * approves, or rejects the ones with `source_app = 'BusinessBillpay'`.
 *
 * Approving a "BusinessPortal"-sourced (B2B) request credits the receiving
 * merchant's `client_prefund` (amount minus SunCash's fee/vat, which is
 * instead credited to a fixed SunCash clearing account, `clients.client_id
 * = 'BUSINESSBP'`); approving a "B2C Payment"-sourced request credits the
 * payee customer's card balance instead. Rejecting always refunds the payor
 * the full amount + fee. No external payment API is involved — same as
 * Merchant Settlements, this only adjusts internal ledgers.
 *
 * Deliberately NOT ported: the SMS notification legacy sends on approval
 * (`settings::send_sms()`) — its gateway/provider isn't part of this
 * codebase and no SMS infrastructure exists in the new system.
 */
class BusinessBillpayService
{
    private const STATUSES = [
        'pending' => BusinessBillTransaction::STATUS_PENDING,
        'processed' => BusinessBillTransaction::STATUS_PROCESSED,
        'rejected' => BusinessBillTransaction::STATUS_REJECTED,
    ];

    private const TRANSACTION_TYPE_LABELS = [
        'B2C Payment' => 'Business To Customer Account',
        'business-sanddollar' => 'Business To SandDollar',
    ];

    private const STATUS_LABELS = ['A' => 'Pending', 'P' => 'Processed', 'R' => 'Rejected'];

    public const COLUMNS = [
        ['key' => 'created_at', 'label' => 'Created'],
        ['key' => 'transaction_id', 'label' => 'Transaction ID'],
        ['key' => 'suntag_shortcode', 'label' => 'Shortcode'],
        ['key' => 'payor', 'label' => 'From'],
        ['key' => 'payee', 'label' => 'To'],
        ['key' => 'transaction_type', 'label' => 'Type'],
        ['key' => 'amount', 'label' => 'Amount'],
        ['key' => 'fee', 'label' => 'Fee'],
        ['key' => 'total', 'label' => 'Total'],
        ['key' => 'status_label', 'label' => 'Status'],
    ];

    private function transactionTypeLabel(?string $source): string
    {
        return self::TRANSACTION_TYPE_LABELS[$source] ?? 'Business To Business';
    }

    private function mapRow(BusinessBillTransaction $bt): array
    {
        $isCustomerPayee = (bool) $bt->is_customer_payee;
        $payeeName = $isCustomerPayee ? $bt->payeeCustomer?->customer_tag : $bt->merchant?->dba_name;
        $payorId = $isCustomerPayee ? $bt->merchant_client_id : $bt->payor_client_id;
        $payorName = $isCustomerPayee ? $bt->merchant?->dba_name : $bt->payor?->dba_name;

        return [
            'id' => $bt->transaction_id,
            'transaction_id' => sprintf('%08d', $bt->transaction_id),
            'suntag_shortcode' => $bt->merchant?->suntag_shortcode,
            'dba_name' => $bt->merchant?->dba_name,
            'client_record_id' => $bt->merchant_client_id,
            'transaction_type' => $this->transactionTypeLabel($bt->source),
            'payor_client_id' => $payorId,
            'payor' => $payorName,
            'payee' => $payeeName,
            'amount' => (float) $bt->amount,
            'fee' => (float) $bt->fee,
            'vat' => (float) $bt->vat,
            'total' => (float) $bt->amount + (float) $bt->fee,
            'status' => $bt->status,
            'reference_number' => $bt->reference_number,
            'created_at' => $bt->created_at,
            'updated_date' => $bt->update_date,
        ];
    }

    public function list(): array
    {
        $result = [];
        foreach (self::STATUSES as $key => $status) {
            $result[$key] = BusinessBillTransaction::with(['merchant', 'payor', 'payeeCustomer'])
                ->where('status', $status)
                ->where('source_app', BusinessBillTransaction::SOURCE_APP)
                ->orderBy('transaction_id')
                ->get()
                ->map(fn (BusinessBillTransaction $bt) => $this->mapRow($bt))
                ->all();
        }

        return $result;
    }

    /**
     * Rows for PDF/Excel export, optionally scoped to one tab's status (A/P/R).
     */
    public function exportRows(?string $status = null): array
    {
        $query = BusinessBillTransaction::with(['merchant', 'payor', 'payeeCustomer'])
            ->where('source_app', BusinessBillTransaction::SOURCE_APP);
        if ($status) {
            $query->where('status', $status);
        }

        return $query->orderByDesc('transaction_id')->get()->map(function (BusinessBillTransaction $bt) {
            $row = $this->mapRow($bt);
            $row['status_label'] = self::STATUS_LABELS[$row['status']] ?? $row['status'];

            return $row;
        })->all();
    }

    /**
     * @throws ValidationException
     */
    private function findOrFail(int $id): BusinessBillTransaction
    {
        $bt = BusinessBillTransaction::with(['merchant', 'payor', 'payeeCustomer'])
            ->where('source_app', BusinessBillTransaction::SOURCE_APP)
            ->find($id);

        if (! $bt) {
            throw ValidationException::withMessages(['id' => ['Billpay request not found.']]);
        }

        return $bt;
    }

    /**
     * @throws ValidationException
     */
    public function getDetail(int $id): array
    {
        $bt = $this->findOrFail($id);
        $isCustomerPayee = (bool) $bt->is_customer_payee;

        return $this->mapRow($bt) + [
            'status_text' => ['A' => 'Pending', 'P' => 'Processed', 'R' => 'Rejected'][$bt->status] ?? 'Unknown',
            'source' => $bt->source,
            'notes' => $bt->customer_note,
            'business_note' => $bt->business_note,
            'payor_suntag_shortcode' => $isCustomerPayee ? $bt->merchant?->suntag_shortcode : $bt->payor?->suntag_shortcode,
            'payee_suntag_shortcode' => $isCustomerPayee ? null : $bt->merchant?->suntag_shortcode,
            'payment_fee' => (float) $bt->payment_fee,
            'payment_suncash_vat' => (float) $bt->payment_suncash_vat,
            'is_customer_payee' => $isCustomerPayee,
        ];
    }

    /**
     * @throws ValidationException
     */
    public function reject(int $id, string $actorId, string $actorUserId): array
    {
        $bt = $this->findOrFail($id);

        if ($bt->status === BusinessBillTransaction::STATUS_REJECTED) {
            throw ValidationException::withMessages(['status' => ['This request has already been rejected.']]);
        }

        $isCustomerPayee = (bool) $bt->is_customer_payee;
        $payorId = $isCustomerPayee ? $bt->merchant_client_id : $bt->payor_client_id;
        $payor = Merchant::find($payorId);

        if (! $payor) {
            throw ValidationException::withMessages(['id' => ['Payor merchant not found.']]);
        }

        $refund = (float) $bt->amount + (float) $bt->fee;
        $payeeName = $isCustomerPayee ? $bt->payeeCustomer?->customer_tag : $bt->merchant?->dba_name;

        DB::connection('mysuncash')->transaction(function () use ($bt, $payor, $refund, $payeeName, $actorId) {
            $this->creditMerchant($payor, $refund, $bt->transaction_id, 'Cancelled Payment to '.$payeeName);

            $bt->update([
                'status' => BusinessBillTransaction::STATUS_REJECTED,
                'update_by' => $actorId,
                'update_date' => now(),
            ]);
        });

        ActivityLog::recordAction(User::find($actorUserId), 'Business Billpay', 'rejected', 'Rejected business billpay request #'.sprintf('%08d', $bt->transaction_id), $bt, null);

        return ['message' => 'Request has been rejected.'];
    }

    /**
     * @throws ValidationException
     */
    public function approve(int $id, string $actorId, string $actorUserId): array
    {
        $bt = $this->findOrFail($id);

        if ($bt->status !== BusinessBillTransaction::STATUS_PENDING) {
            throw ValidationException::withMessages(['status' => ['This request has already been processed.']]);
        }

        $result = (bool) $bt->is_customer_payee
            ? $this->approveB2c($bt, $actorId)
            : $this->approveB2b($bt, $actorId);

        ActivityLog::recordAction(User::find($actorUserId), 'Business Billpay', 'approved', 'Approved business billpay request #'.sprintf('%08d', $bt->transaction_id), $bt, null);

        return $result;
    }

    /**
     * @throws ValidationException
     */
    private function approveB2b(BusinessBillTransaction $bt, string $actorId): array
    {
        $receiver = $bt->merchant;
        if (! $receiver) {
            throw ValidationException::withMessages(['id' => ['Receiving merchant not found.']]);
        }

        $suncashFee = (float) $bt->payment_fee + (float) $bt->payment_suncash_vat;
        $netAmount = (float) $bt->amount - $suncashFee;
        $payorName = $bt->payor?->dba_name ?? 'a business';

        $clearing = Merchant::where('client_id', 'BUSINESSBP')->first();

        DB::connection('mysuncash')->transaction(function () use ($bt, $receiver, $netAmount, $suncashFee, $payorName, $clearing, $actorId) {
            $this->creditMerchant($receiver, $netAmount, $bt->transaction_id, 'Business Payment from '.$payorName);

            if ($clearing && $suncashFee > 0) {
                $this->creditMerchant($clearing, $suncashFee, $bt->transaction_id, 'Business Billpay Credit', skipHistory: true);
            }

            $bt->update([
                'status' => BusinessBillTransaction::STATUS_PROCESSED,
                'update_by' => $actorId,
                'update_date' => now(),
            ]);
        });

        return ['message' => 'Request has been approved.'];
    }

    /**
     * @throws ValidationException
     */
    private function approveB2c(BusinessBillTransaction $bt, string $actorId): array
    {
        $customer = Customer::find($bt->payee_customer_id);
        if (! $customer || ! $customer->ezkard_account_id) {
            throw ValidationException::withMessages(['id' => ['Payee customer or card account not found.']]);
        }

        $ezkard = EzkardAccount::find($customer->ezkard_account_id);
        if (! $ezkard) {
            throw ValidationException::withMessages(['id' => ['Payee card account not found.']]);
        }

        $amount = (float) $bt->amount;
        $payorName = $bt->merchant?->dba_name ?? 'a business';

        DB::connection('mysuncash')->transaction(function () use ($bt, $customer, $ezkard, $amount, $payorName, $actorId) {
            $ezkard->increment('card_balance', $amount);
            $ezkard->refresh();

            EzkardTransaction::create([
                'merchant_id' => $bt->merchant_client_id,
                'ezkard_id' => $ezkard->id,
                'terminal_id' => 97,
                'transaction_id' => (string) now()->timestamp,
                'amount' => $amount,
                'trans_type_id' => 97,
                'description' => 'Business Payment from '.$payorName,
                'reference_id' => (string) $bt->transaction_id,
                'timestamp' => now(),
                'trans_status_id' => 0,
                'running_balance' => $ezkard->card_balance,
                'merchant_terminal_users_id' => $actorId,
            ]);

            CustomerTransactionHistory::create([
                'customer_id' => $customer->id,
                'transaction_reference' => (string) $bt->transaction_id,
                'transaction_type' => 'BusinessToPerson',
                'category' => 'BUSINESS_BILLPAY',
                'status' => 'PAID',
                'description' => 'Payment from Business to Person',
                'amount' => $amount,
                'transaction_fee' => 0,
                'sending_fee' => 0,
                'vat' => 0,
                'channel' => 'Business Portal',
                'running_balance' => $ezkard->card_balance,
                'finance_orientation' => 'CREDIT',
                'created_date' => now(),
            ]);

            $bt->update([
                'status' => BusinessBillTransaction::STATUS_PROCESSED,
                'update_by' => $actorId,
                'update_date' => now(),
            ]);
        });

        return ['message' => 'Request has been approved.'];
    }

    private function creditMerchant(Merchant $merchant, float $amount, int $refTransId, string $description, bool $skipHistory = false): void
    {
        $merchant->increment('client_prefund', $amount);
        $merchant->refresh();

        $transaction = ClientTransaction::create([
            'client_record_id' => $merchant->id,
            'user_type_id' => 2,
            'ref_trans_id' => $refTransId,
            'trans_type_id' => 25,
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

        if (! $skipHistory) {
            MerchantTransactionHistory::create([
                'client_record_id' => $merchant->id,
                'system_services_code' => 'BUSINESSPAYMENT',
                'type' => 'CREDIT',
                'source' => '',
                'destination' => '',
                'description' => $description,
                'amount' => $amount,
                'reference' => $refTransId,
                'running_balance' => $merchant->client_prefund,
            ]);
        }
    }
}
