<?php

namespace App\Services\Transactions;

use App\Models\ActivityLog;
use App\Models\Mysuncash\BusinessBillTransaction;
use App\Models\Mysuncash\CashoutTransactionV3;
use App\Models\Mysuncash\Customer;
use App\Models\Mysuncash\EzkardAccount;
use App\Models\Mysuncash\EzkardTransaction;
use App\Models\Mysuncash\MerchantVoucher;
use App\Models\Mysuncash\UniversalVoucher;
use App\Models\Mysuncash\WebposTransaction;
use App\Models\User;
use App\Services\Transactions\Support\LedgerAdjuster;
use App\Services\Transactions\Support\TransactionRowFetcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Transactions > Void Transaction" — legacy `Tools::void_transaction()` /
 * `Void_model::void_transaction()` and `search_transaction()`. Legacy's
 * dropdown lists ~17 transaction types; two are deliberately NOT ported:
 *
 * - BILLPAY: legacy's void first calls an EXTERNAL biller-gateway API
 *   (`void_billpay_get_curl_result`) and only reverses local balances if
 *   THAT succeeds. Not configured in this codebase — faking success would
 *   tell an admin a bill payment was cancelled with the biller when it
 *   wasn't, which is worse than not offering the feature at all.
 * - LOCALGC: same problem — legacy calls an external Bahamasair giftcard
 *   API (`voidGiftcard`) before reversing anything locally.
 *
 * The other 15 types (RELOAD/SALE ported earlier; the other 13 here) are
 * pure internal database operations and are fully ported. Several introduce
 * a merchant-balance reversal pattern (`clients.client_prefund`/
 * `client_settlement` via `client_transactions`/`client_transaction_details`)
 * that mirrors legacy's `_adjust_client_balance()` — the first place in this
 * rewrite that touches those tables generically rather than through a single
 * merchant's own settlement/prefund screens. That reversal logic, plus the
 * per-type row lookups shared with Resend Transaction Receipt, live in
 * `Support\LedgerAdjuster` and `Support\TransactionRowFetcher` respectively
 * rather than here, so either can be reused without duplicating them again.
 *
 * Several legacy branches (MONEY_TRANSFER, CASHOUT_CODE, CASHOUT_MOBILE,
 * DONATION, CHECKCASHING) have NO "already voided" guard at all — re-running
 * the same void just re-applies the balance reversal a second time. This
 * port adds one (checking `webpos_transaction.status`) for all of them,
 * since that's clearly the intent, not a deliberate design choice.
 */
class VoidTransactionService
{
    public const TYPES = [
        'RELOAD' => 'Load',
        'SALE' => 'Purchase',
        'MONEY_TRANSFER' => 'Money Transfer',
        'PHONE2PHONE' => 'Phone to Phone',
        'PHONE2STORE' => 'Phone to Store',
        'CASHOUT_CODE' => 'Cashout by Code',
        'CASHOUT_MOBILE' => 'Cashout by Mobile',
        'LOAD_CASHOUTCODE' => 'Load via Cashout Code',
        'BUSINESS_BILLPAY' => 'Business Billpay',
        'BUSINESS_BILLPAY_STORE' => 'Business Billpay (Store)',
        'CUSTOMERSPAYMENT' => "Customer's Payment",
        'DONATION' => 'Donation',
        'CHECKCASHING' => 'Check Cashing',
        'SUNCASH_VOUCHER' => 'SunCash Voucher',
        'UNIBUCKS_VOUCHER' => 'UniBucks Voucher',
    ];

    // Fixed system/clearing merchant accounts void logic reverses money
    // through — same internal `clients.id` values legacy resolves via
    // get_client_id_by_merchant_id('<business client_id>') each call.
    private const FLOAT_CLIENT_ID = 53;        // clients.client_id = '35628' ("Float - Cashout")

    private const BUSINESSBP_CLIENT_ID = 138;  // clients.client_id = 'BUSINESSBP'

    private const CHECKCASHING_CLIENT_ID = 116; // clients.client_id = 'CHECKCASHING'

    public function __construct(
        private readonly TransactionRowFetcher $rows,
        private readonly LedgerAdjuster $ledger,
    ) {}

    private function present(array $row): array
    {
        return array_merge([
            'transaction_id' => null,
            'transaction_type' => null,
            'transaction_type_label' => null,
            'customer_name' => null,
            'mobile' => null,
            'amount' => 0.0,
            'timestamp' => null,
            'status' => 'active',
        ], $row);
    }

    // ── search dispatch ──────────────────────────────────────────────────────

    /**
     * @throws ValidationException
     */
    public function search(string $transactionId, string $transactionType): array
    {
        if (! array_key_exists($transactionType, self::TYPES)) {
            throw ValidationException::withMessages(['transaction_type' => ['Select a valid transaction type.']]);
        }

        $rows = match ($transactionType) {
            'RELOAD', 'SALE' => $this->searchCardLedger($transactionId, $transactionType),
            'MONEY_TRANSFER' => $this->searchMoneyTransfer($transactionId),
            'PHONE2PHONE' => $this->searchPhoneToPhone($transactionId),
            'PHONE2STORE' => $this->searchPhoneToStore($transactionId),
            'CASHOUT_CODE' => $this->searchCashoutCode($transactionId),
            'CASHOUT_MOBILE' => $this->searchCashoutMobile($transactionId),
            'LOAD_CASHOUTCODE' => $this->searchLoadCashoutCode($transactionId),
            'BUSINESS_BILLPAY' => $this->searchBusinessBillpay($transactionId, false),
            'BUSINESS_BILLPAY_STORE' => $this->searchBusinessBillpay($transactionId, true),
            'CUSTOMERSPAYMENT' => $this->searchCustomersPayment($transactionId),
            'DONATION' => $this->searchDonation($transactionId),
            'CHECKCASHING' => $this->searchCheckCashing($transactionId),
            'SUNCASH_VOUCHER' => $this->searchVoucher($transactionId, MerchantVoucher::class, 'SUNCASH_VOUCHER'),
            'UNIBUCKS_VOUCHER' => $this->searchVoucher($transactionId, UniversalVoucher::class, 'UNIBUCKS_VOUCHER'),
        };

        return array_map(fn (array $row) => $this->present($row), $rows);
    }

    // ── void dispatch ────────────────────────────────────────────────────────

    /**
     * @throws ValidationException
     */
    public function void(string $transactionId, string $transactionType, string $actorId): array
    {
        if (! array_key_exists($transactionType, self::TYPES)) {
            throw ValidationException::withMessages(['transaction_type' => ['Select a valid transaction type.']]);
        }

        $result = match ($transactionType) {
            'RELOAD', 'SALE' => $this->voidCardLedger($transactionId, $transactionType),
            'MONEY_TRANSFER' => $this->voidMoneyTransfer($transactionId),
            'PHONE2PHONE' => $this->voidPhoneToPhone($transactionId),
            'PHONE2STORE' => $this->voidPhoneToStore($transactionId),
            'CASHOUT_CODE' => $this->voidCashoutCode($transactionId),
            'CASHOUT_MOBILE' => $this->voidCashoutMobile($transactionId),
            'LOAD_CASHOUTCODE' => $this->voidLoadCashoutCode($transactionId),
            'BUSINESS_BILLPAY' => $this->voidBusinessBillpay($transactionId, false),
            'BUSINESS_BILLPAY_STORE' => $this->voidBusinessBillpay($transactionId, true),
            'CUSTOMERSPAYMENT' => $this->voidCustomersPayment($transactionId),
            'DONATION' => $this->voidDonation($transactionId),
            'CHECKCASHING' => $this->voidCheckCashing($transactionId),
            'SUNCASH_VOUCHER' => $this->voidVoucher($transactionId, MerchantVoucher::class, 'SUNCASH_VOUCHER'),
            'UNIBUCKS_VOUCHER' => $this->voidVoucher($transactionId, UniversalVoucher::class, 'UNIBUCKS_VOUCHER'),
        };

        ActivityLog::recordAction(User::find($actorId), 'Void Transaction', 'voided', "Voided {$transactionId} ({$transactionType})", null, null);

        return $result;
    }

    // ── RELOAD / SALE (ezkard_transactions only) ────────────────────────────

    private function searchCardLedger(string $transactionId, string $transactionType): array
    {
        $typeId = $transactionType === 'RELOAD' ? 0 : 1;

        return $this->rows->cardLedgerTransactions($transactionId, $typeId)
            ->map(function (EzkardTransaction $t) use ($transactionType) {
                $ezkard = EzkardAccount::find($t->ezkard_id);
                $customer = $this->rows->customerByEzkardId($t->ezkard_id);

                return [
                    'transaction_id' => $t->transaction_id,
                    'transaction_type' => $transactionType,
                    'transaction_type_label' => self::TYPES[$transactionType],
                    'customer_name' => $customer ? trim($customer->first_name.' '.$customer->last_name) : null,
                    'mobile' => $ezkard?->mobile_number,
                    'amount' => (float) $t->amount,
                    'timestamp' => $t->timestamp,
                    'status' => (int) $t->trans_status_id === 1 ? 'voided' : 'active',
                ];
            })->all();
    }

    /**
     * @throws ValidationException
     */
    private function voidCardLedger(string $transactionId, string $transactionType): array
    {
        $typeId = $transactionType === 'RELOAD' ? 0 : 1;
        $transaction = EzkardTransaction::where('transaction_id', $transactionId)->where('trans_type_id', $typeId)->first();
        if (! $transaction) {
            throw ValidationException::withMessages(['id' => ['Transaction not found.']]);
        }
        if ((int) $transaction->trans_status_id === 1) {
            throw ValidationException::withMessages(['id' => ['This transaction has already been voided.']]);
        }

        $isReload = $transactionType === 'RELOAD';
        $amount = (float) $transaction->amount;
        $ezkard = EzkardAccount::find($transaction->ezkard_id);
        if (! $ezkard) {
            throw ValidationException::withMessages(['id' => ['Linked card account not found.']]);
        }
        if ($isReload && (float) $ezkard->card_balance < $amount) {
            throw ValidationException::withMessages(['amount' => ['Insufficient customer balance.']]);
        }

        $customer = $this->rows->customerByEzkardId($ezkard->id);

        DB::connection('mysuncash')->transaction(function () use ($transaction, $ezkard, $customer, $isReload, $amount) {
            $transaction->update(['trans_status_id' => 1]);

            $this->ledger->adjustCardBalance(
                $ezkard->id,
                $isReload ? 'less' : 'add',
                $amount,
                $isReload ? 1 : 0,
                ($isReload ? 'Void Reload' : 'Void Sale')." ({$transaction->transaction_id})",
                $transaction->merchant_id,
                $transaction->id,
            );

            $this->ledger->logCustomerHistory($customer, $ezkard->id, $this->ledger->nextTransactionId(), 'VoidTransaction', 'ADJUSTMENT', ($isReload ? 'Void Reload' : 'Void Sale')." ({$transaction->transaction_id})", $amount, $isReload ? 'DEBIT' : 'CREDIT');
        });

        return ['message' => 'Transaction has been voided successfully.'];
    }

    // ── MONEY_TRANSFER ───────────────────────────────────────────────────────

    private function searchMoneyTransfer(string $transactionId): array
    {
        $r = $this->rows->moneyTransferRow($transactionId);
        if (! $r) {
            return [];
        }

        return [[
            'transaction_id' => $transactionId,
            'transaction_type' => 'MONEY_TRANSFER',
            'transaction_type_label' => self::TYPES['MONEY_TRANSFER'],
            'customer_name' => trim(($r->sender_fname ?? '').' '.($r->sender_lname ?? '')) ?: null,
            'mobile' => $r->sender_mobile ?? $r->bene_mobile ?? null,
            'amount' => (float) $r->amount,
            'timestamp' => $r->transaction_date,
            'status' => (int) $r->webpos_status === 1 ? 'voided' : 'active',
        ]];
    }

    /**
     * @throws ValidationException
     */
    private function voidMoneyTransfer(string $transactionId): array
    {
        $r = $this->rows->moneyTransferRow($transactionId);
        if (! $r) {
            throw ValidationException::withMessages(['id' => ['Transaction not found.']]);
        }
        if ((int) $r->webpos_status === 1) {
            throw ValidationException::withMessages(['id' => ['This transaction has already been voided.']]);
        }

        $amount = (float) $r->amount;

        DB::connection('mysuncash')->transaction(function () use ($r, $transactionId, $amount) {
            if (filled($r->cashout_reference)) {
                // Path A — cash pickup: reverses merchant/float prefund only, no customer card touched.
                $this->ledger->adjustClientBalance((int) $r->merchant_id, 'prefund', 'add', $amount, 46, 'Void Generate Cashout Code (debit)', $transactionId);
                $this->ledger->adjustClientBalance(self::FLOAT_CLIENT_ID, 'prefund', 'less', $amount, 45, 'Void Generate Cashout Code (credit)', $transactionId);
            } else {
                // Path B — credit to wallet: reverses the customer's card credit.
                $customer = Customer::where('mobile', $r->bene_mobile)->first();
                if (! $customer || ! $customer->ezkard_account_id) {
                    throw ValidationException::withMessages(['id' => ['Invalid customer record.']]);
                }
                $this->ledger->adjustCardBalance($customer->ezkard_account_id, 'less', $amount, 18, 'Money Transfer - Credit - VOIDED', (int) $r->merchant_id, $transactionId);
                $this->ledger->adjustClientBalance((int) $r->merchant_id, 'prefund', 'add', $amount, 18, 'Void Money Transfer - Debit', $transactionId);
            }

            WebposTransaction::where('transaction_type', 'MONEY_TRANSFER')->where('transaction_id', $transactionId)->update(['status' => 1]);
            if (filled($r->cashout_reference)) {
                CashoutTransactionV3::where('id', $transactionId)->update(['status' => 3]);
            }
        });

        return ['message' => 'Transaction has been voided successfully.'];
    }

    // ── PHONE2PHONE ──────────────────────────────────────────────────────────

    private function searchPhoneToPhone(string $transactionId): array
    {
        $r = $this->rows->phoneToPhoneRow($transactionId);
        if (! $r) {
            return [];
        }

        return [[
            'transaction_id' => $transactionId,
            'transaction_type' => 'PHONE2PHONE',
            'transaction_type_label' => self::TYPES['PHONE2PHONE'],
            'customer_name' => trim(($r->first_name ?? '').' '.($r->last_name ?? '')) ?: null,
            'mobile' => $r->mobile,
            'amount' => (float) $r->amount,
            'timestamp' => $r->timestamp,
            'status' => (int) $r->trans_status_id === 1 ? 'voided' : 'active',
        ]];
    }

    /**
     * @throws ValidationException
     */
    private function voidPhoneToPhone(string $transactionId): array
    {
        $r = $this->rows->phoneToPhoneRow($transactionId);
        if (! $r || (int) $r->trans_status_id === 1) {
            throw ValidationException::withMessages(['id' => ['Unable to void transaction.']]);
        }

        $totalAmount = (float) $r->amount + (float) ($r->transaction_fee ?? 0) + (float) ($r->vat ?? 0);
        $receiverTransactionId = $r->reference_id;
        $receiver = $receiverTransactionId ? $this->rows->phoneToPhoneReceiverRow((string) $receiverTransactionId) : null;

        DB::connection('mysuncash')->transaction(function () use ($r, $transactionId, $totalAmount, $receiver, $receiverTransactionId) {
            // Refund the sender.
            $senderCustomer = $this->rows->customerByEzkardId($r->ezkard_account_id);
            $this->ledger->adjustCardBalance($r->ezkard_account_id, 'add', $totalAmount, 17, $r->description.' - REFUND', null, $r->transaction_reference);
            $this->ledger->logCustomerHistory($senderCustomer, $r->ezkard_account_id, (string) $r->transaction_reference, 'PHONE2PHONE', 'MONEYSENT', $r->description.' - REFUND', $totalAmount, 'CREDIT');

            // Claw back the receiver's credited leg, if found.
            if ($receiver) {
                $receiverTotal = (float) $receiver->amount + (float) ($receiver->transaction_fee ?? 0) + (float) ($receiver->vat ?? 0);
                $receiverCustomer = $this->rows->customerByEzkardId($receiver->ezkard_account_id);
                $this->ledger->adjustCardBalance($receiver->ezkard_account_id, 'less', $receiverTotal, 16, $receiver->description.' - REFUND', null, $receiver->transaction_reference);
                $this->ledger->logCustomerHistory($receiverCustomer, $receiver->ezkard_account_id, (string) $receiver->transaction_reference, 'PHONE2PHONE', 'MONEYSENT', $receiver->description.' - REFUND', $receiverTotal, 'DEBIT');
                EzkardTransaction::where('transaction_id', $receiverTransactionId)->where('trans_type_id', 17)->update(['trans_status_id' => 1]);
            }

            EzkardTransaction::where('transaction_id', $transactionId)->where('trans_type_id', 16)->update(['trans_status_id' => 1]);
        });

        return ['message' => 'Transaction has been voided successfully.'];
    }

    // ── PHONE2STORE ──────────────────────────────────────────────────────────

    private function searchPhoneToStore(string $transactionId): array
    {
        $r = $this->rows->phoneToStoreRow($transactionId);
        if (! $r) {
            return [];
        }

        return [[
            'transaction_id' => $transactionId,
            'transaction_type' => 'PHONE2STORE',
            'transaction_type_label' => self::TYPES['PHONE2STORE'],
            'customer_name' => trim(($r->first_name ?? '').' '.($r->last_name ?? '')) ?: null,
            'mobile' => $r->mobile,
            'amount' => (float) $r->amount,
            'timestamp' => $r->timestamp,
            'status' => (int) $r->trans_status_id === 1 ? 'voided' : 'active',
        ]];
    }

    /**
     * @throws ValidationException
     */
    private function voidPhoneToStore(string $transactionId): array
    {
        $r = $this->rows->phoneToStoreRow($transactionId);
        if (! $r || (int) $r->trans_status_id === 1) {
            throw ValidationException::withMessages(['id' => ['Unable to void transaction.']]);
        }

        $totalAmount = (float) $r->amount + (float) ($r->transaction_fee ?? 0) + (float) ($r->vat ?? 0);
        $customer = $this->rows->customerByEzkardId($r->ezkard_account_id);

        DB::connection('mysuncash')->transaction(function () use ($r, $transactionId, $totalAmount, $customer) {
            $this->ledger->adjustCardBalance($r->ezkard_account_id, 'add', $totalAmount, 50, $r->description.' - REFUND', null, $r->transaction_reference);
            $this->ledger->logCustomerHistory($customer, $r->ezkard_account_id, (string) $r->transaction_reference, 'PHONE2STORE', 'MONEYSENT', $r->description.' - REFUND', $totalAmount, 'CREDIT');
            EzkardTransaction::where('transaction_id', $transactionId)->where('trans_type_id', 47)->update(['trans_status_id' => 1]);
        });

        return ['message' => 'Transaction has been voided successfully.'];
    }

    // ── CASHOUT_CODE ─────────────────────────────────────────────────────────

    private function searchCashoutCode(string $transactionId): array
    {
        $r = $this->rows->cashoutCodeRow($transactionId);
        if (! $r) {
            return [];
        }

        return [[
            'transaction_id' => $transactionId,
            'transaction_type' => 'CASHOUT_CODE',
            'transaction_type_label' => self::TYPES['CASHOUT_CODE'],
            'customer_name' => trim(($r->sender_fname ?? '').' '.($r->sender_lname ?? '')) ?: null,
            'mobile' => $r->sender_mobile ?? null,
            'amount' => (float) $r->amount,
            'timestamp' => $r->transaction_date,
            'status' => (int) $r->webpos_status === 1 ? 'voided' : 'active',
        ]];
    }

    /**
     * @throws ValidationException
     */
    private function voidCashoutCode(string $transactionId): array
    {
        $r = $this->rows->cashoutCodeRow($transactionId);
        if (! $r) {
            throw ValidationException::withMessages(['id' => ['Transaction not found.']]);
        }
        if ((int) $r->webpos_status === 1) {
            throw ValidationException::withMessages(['id' => ['This transaction has already been voided.']]);
        }

        $amount = (float) $r->amount;

        DB::connection('mysuncash')->transaction(function () use ($r, $transactionId, $amount) {
            $this->ledger->adjustClientBalance(self::FLOAT_CLIENT_ID, 'prefund', 'add', $amount, 49, 'Void Cashout by Code - Redemption (debit)', $transactionId);
            $this->ledger->adjustClientBalance((int) $r->merchant_id, 'settlement', 'less', $amount, 50, 'Void Cashout by Code - Redemption (credit)', $transactionId);
            WebposTransaction::where('transaction_type', 'CASHOUT_CODE')->where('transaction_id', $transactionId)->update(['status' => 1]);
        });

        return ['message' => 'Transaction has been voided successfully.'];
    }

    // ── CASHOUT_MOBILE ───────────────────────────────────────────────────────

    private function searchCashoutMobile(string $transactionId): array
    {
        $r = $this->rows->cashoutMobileRow($transactionId);
        if (! $r) {
            return [];
        }

        return [[
            'transaction_id' => $transactionId,
            'transaction_type' => 'CASHOUT_MOBILE',
            'transaction_type_label' => self::TYPES['CASHOUT_MOBILE'],
            'customer_name' => trim(($r->first_name ?? '').' '.($r->last_name ?? '')) ?: null,
            'mobile' => $r->mobile,
            'amount' => (float) $r->amount,
            'timestamp' => $r->transaction_date,
            'status' => (int) $r->webpos_status === 1 ? 'voided' : 'active',
        ]];
    }

    /**
     * @throws ValidationException
     */
    private function voidCashoutMobile(string $transactionId): array
    {
        $r = $this->rows->cashoutMobileRow($transactionId);
        if (! $r) {
            throw ValidationException::withMessages(['id' => ['Transaction not found.']]);
        }
        if ((int) $r->webpos_status === 1) {
            throw ValidationException::withMessages(['id' => ['This transaction has already been voided.']]);
        }

        $totalAmount = (float) $r->amount + (float) $r->fee_amount;
        $customer = $r->customer_id ? Customer::find($r->customer_id) : null;

        DB::connection('mysuncash')->transaction(function () use ($r, $transactionId, $totalAmount, $customer) {
            $this->ledger->adjustCardBalance($r->ezkard_id, 'add', $totalAmount, 21, 'Void POS Cash Out by Mobile', (int) $r->merchant_id, $transactionId);
            $this->ledger->adjustClientBalance((int) $r->merchant_id, 'settlement', 'add', $totalAmount, 21, 'Void POS Cash Out by Mobile', $transactionId);
            $this->ledger->logCustomerHistory($customer, $r->ezkard_id, $transactionId, 'CASHOUT_MOBILE', 'CASHOUT', 'Void POS Cash Out by Mobile', $totalAmount, 'CREDIT');
            EzkardTransaction::where('transaction_id', $transactionId)->where('trans_type_id', 20)->update(['trans_status_id' => 1]);
            WebposTransaction::where('transaction_type', 'CASHOUT_MOBILE')->where('transaction_id', $transactionId)->update(['status' => 1]);
        });

        return ['message' => 'Transaction has been voided successfully.'];
    }

    // ── LOAD_CASHOUTCODE ─────────────────────────────────────────────────────

    private function loadCashoutCodeRow(string $transactionId): ?object
    {
        return DB::connection('mysuncash')->table('ezkard_transactions as et')
            ->join('customer_transaction_histories as cth', 'cth.transaction_reference', '=', 'et.transaction_id')
            ->join('customers as c', 'cth.customer_id', '=', 'c.id')
            ->join('cashout_transactionsv3 as ct', 'ct.cashout_reference', '=', 'et.reference_id')
            ->where('et.transaction_id', $transactionId)
            ->orderByDesc('et.id')
            ->select('et.*', 'cth.customer_id', 'c.first_name', 'c.last_name', 'c.mobile', 'ct.status as cashout_status')
            ->first();
    }

    private function searchLoadCashoutCode(string $transactionId): array
    {
        $r = $this->loadCashoutCodeRow($transactionId);
        if (! $r) {
            return [];
        }

        // The cashout code's own redemption state (REDEEMED/ACTIVE/PROCESSED,
        // matching legacy's own 3-way label) is purely informational —
        // whether this row can still be voided is governed by the
        // underlying `ezkard_transactions.trans_status_id`, same as
        // void()'s own guard, NOT by whether the code has been redeemed.
        $statusLabel = match ((string) $r->cashout_status) {
            '1' => 'Redeemed',
            '0' => 'Active',
            default => 'Processed',
        };

        return [[
            'transaction_id' => $transactionId,
            'transaction_type' => 'LOAD_CASHOUTCODE',
            'transaction_type_label' => self::TYPES['LOAD_CASHOUTCODE'],
            'customer_name' => trim(($r->first_name ?? '').' '.($r->last_name ?? '')) ?: null,
            'mobile' => $r->mobile,
            'amount' => (float) $r->amount,
            'timestamp' => $r->timestamp,
            'status' => (int) $r->trans_status_id === 1 ? 'voided' : 'active',
            'status_label' => $statusLabel,
        ]];
    }

    /**
     * @throws ValidationException
     */
    private function voidLoadCashoutCode(string $transactionId): array
    {
        $r = DB::connection('mysuncash')->table('ezkard_transactions as et')
            ->join('customer_transaction_histories as cth', 'cth.transaction_reference', '=', 'et.transaction_id')
            ->where('et.transaction_id', $transactionId)
            ->where('et.trans_status_id', '!=', 1)
            ->select('et.*', 'cth.customer_id')
            ->first();
        if (! $r) {
            throw ValidationException::withMessages(['id' => ['Unable to void transaction.']]);
        }

        $cashoutCode = $r->reference_id;
        $cashout = DB::connection('mysuncash')->table('cashout_transactionsv3 as ct')
            ->leftJoin('cashout_transaction_detailsv3 as ctd', 'ct.id', '=', 'ctd.cashout_id')
            ->where('ct.cashout_reference', $cashoutCode)
            ->select('ct.*')
            ->first();
        if (! $cashout) {
            throw ValidationException::withMessages(['id' => ['Unable to void cashout transaction.']]);
        }

        $amount = (float) $cashout->amount;
        $customer = $r->customer_id ? Customer::find($r->customer_id) : null;

        DB::connection('mysuncash')->transaction(function () use ($r, $transactionId, $cashoutCode, $cashout, $amount, $customer) {
            $this->ledger->adjustCardBalance($r->ezkard_id, 'less', $amount, 50, $r->description.' - Voided', null, $transactionId);
            $this->ledger->logCustomerHistory($customer, $r->ezkard_id, $transactionId, 'LOAD', 'LOAD', 'SunCash Load Via Cashout Code - Voided', $amount, 'DEBIT');
            $this->ledger->adjustClientBalance(self::FLOAT_CLIENT_ID, 'prefund', 'add', $amount, 49, 'Cashout by Code - Redemption (debit) - Voided', $transactionId);

            if ((int) $cashout->status === 1) {
                $this->ledger->adjustClientBalance(37, 'settlement', 'less', $amount, 50, 'Cashout by Code - Redemption (credit) - VOIDED', $transactionId);
            }

            CashoutTransactionV3::where('cashout_reference', $cashoutCode)->update(['status' => 0]);
            EzkardTransaction::where('transaction_id', $transactionId)->update(['trans_status_id' => 1]);
        });

        return ['message' => 'Transaction has been voided successfully.'];
    }

    // ── BUSINESS_BILLPAY / BUSINESS_BILLPAY_STORE ───────────────────────────

    private function searchBusinessBillpay(string $transactionId, bool $store): array
    {
        $r = $this->rows->businessBillpayRow($transactionId, $store);
        if (! $r) {
            return [];
        }

        return [[
            'transaction_id' => $transactionId,
            'transaction_type' => $store ? 'BUSINESS_BILLPAY_STORE' : 'BUSINESS_BILLPAY',
            'transaction_type_label' => self::TYPES[$store ? 'BUSINESS_BILLPAY_STORE' : 'BUSINESS_BILLPAY'],
            'customer_name' => $r->customer_name,
            'mobile' => $r->mobile_number,
            'amount' => (float) $r->amount,
            'timestamp' => $r->transaction_date,
            'status' => $r->status === 'V' ? 'voided' : 'active',
            'status_label' => $this->rows->businessBillpayStatusLabel($r->status),
        ]];
    }

    /**
     * @throws ValidationException
     */
    private function voidBusinessBillpay(string $transactionId, bool $store): array
    {
        $lookupId = $transactionId;
        $ezkardId = null;
        if (! $store) {
            $link = DB::connection('mysuncash')->table('ezkard_transactions')
                ->where('transaction_id', $transactionId)->where('trans_type_id', 63)->first();
            if (! $link) {
                throw ValidationException::withMessages(['id' => ['Unable to void transaction.']]);
            }
            $lookupId = (string) $link->reference_id;
            $ezkardId = (int) $link->ezkard_id;
        }

        $row = BusinessBillTransaction::where('transaction_id', (int) $lookupId)
            ->where('source', '')
            ->where('status', '!=', BusinessBillTransaction::STATUS_VOIDED)
            ->first();
        if (! $row) {
            throw ValidationException::withMessages(['id' => ['Unable to void transaction.']]);
        }

        $amount = (float) $row->amount;
        $status = $row->status;
        $webposMerchantId = $store
            ? WebposTransaction::where('transaction_type', 'BILLPAY')->where('transaction_id', $row->transaction_id)->value('merchant_id')
            : null;

        DB::connection('mysuncash')->transaction(function () use ($row, $store, $amount, $status, $webposMerchantId, $ezkardId) {
            if ($store) {
                // Credits the STORE merchant's own prefund (not the customer's card).
                $this->ledger->adjustClientBalance((int) $webposMerchantId, 'prefund', 'add', $amount, 4, 'Billspay Void Credit', $row->transaction_id);
            } else {
                $this->ledger->adjustCardBalance($ezkardId, 'add', $amount, 62, $row->reference_number.' - VOIDED', (int) $row->merchant_client_id, $row->transaction_id);
                $customer = $this->rows->customerByEzkardId($ezkardId);
                $this->ledger->logCustomerHistory($customer, $ezkardId, (string) $row->transaction_id, 'BUSINESS_BILLPAY', 'BILLPAY', $row->reference_number.' - VOIDED', $amount, 'CREDIT');
            }

            if ($status === BusinessBillTransaction::STATUS_PROCESSED) {
                $this->ledger->adjustClientBalance((int) $row->merchant_client_id, 'prefund', 'less', $amount, 24, 'Business Billpay Debit', $row->transaction_id);
            } elseif (in_array($status, [BusinessBillTransaction::STATUS_PENDING, BusinessBillTransaction::STATUS_SCHEDULED], true)) {
                $this->ledger->adjustClientBalance(self::BUSINESSBP_CLIENT_ID, 'prefund', 'less', $amount, 24, 'Business Billpay Void Credit', $row->transaction_id);
            }

            $row->update(['status' => BusinessBillTransaction::STATUS_VOIDED, 'cancel_note' => '', 'cancellation_date' => now()]);

            if ($store) {
                WebposTransaction::where('transaction_type', 'BILLPAY')->where('transaction_id', $row->transaction_id)->update(['status' => 1]);
            }
        });

        return ['message' => 'Transaction has been voided successfully.'];
    }

    // ── CUSTOMERSPAYMENT ─────────────────────────────────────────────────────

    private function searchCustomersPayment(string $transactionId): array
    {
        $lookupId = $this->rows->customersPaymentLink($transactionId);
        if ($lookupId === null) {
            return [];
        }

        $row = BusinessBillTransaction::where('transaction_id', $lookupId)->where('is_customer_payee', '!=', 0)->first();
        if (! $row) {
            return [];
        }

        return [[
            'transaction_id' => $transactionId,
            'transaction_type' => 'CUSTOMERSPAYMENT',
            'transaction_type_label' => self::TYPES['CUSTOMERSPAYMENT'],
            'customer_name' => null,
            'mobile' => $row->mobile_number,
            'amount' => (float) $row->amount,
            'timestamp' => $row->transaction_date,
            'status' => $row->status === 'V' ? 'voided' : 'active',
            'status_label' => $this->rows->businessBillpayStatusLabel($row->status),
        ]];
    }

    private function computeCustomersPaymentFee(float $amount): float
    {
        $fee = DB::connection('mysuncash')->table('fees')->where('fee_type', 'CUSTOMERSPAYMENT')->first();
        if (! $fee) {
            return 0.0;
        }

        return round($amount * ((float) $fee->percentage / 100), 2) + (float) $fee->fixed;
    }

    /**
     * @throws ValidationException
     */
    private function voidCustomersPayment(string $transactionId): array
    {
        $lookupId = $this->rows->customersPaymentLink($transactionId);
        if ($lookupId === null) {
            throw ValidationException::withMessages(['id' => ["Can't find Transaction."]]);
        }

        $row = BusinessBillTransaction::where('transaction_id', $lookupId)->where('status', '!=', BusinessBillTransaction::STATUS_VOIDED)->first();
        if (! $row || $row->status !== BusinessBillTransaction::STATUS_PROCESSED) {
            throw ValidationException::withMessages(['id' => ['Unable to void transaction.']]);
        }

        $payee = Customer::with('ezkardAccount')->find($row->payee_customer_id);
        if (! $payee || ! $payee->ezkard_account_id) {
            throw ValidationException::withMessages(['id' => ['Unable to find payee.']]);
        }

        $amount = (float) $row->amount;
        $fee = $this->computeCustomersPaymentFee($amount);
        $totalAmount = $amount - $fee;

        DB::connection('mysuncash')->transaction(function () use ($row, $payee, $amount, $fee, $totalAmount, $transactionId) {
            $this->ledger->adjustCardBalance($payee->ezkard_account_id, 'add', $amount, 25, 'Void - Customers Payment', self::BUSINESSBP_CLIENT_ID, $row->transaction_id);
            $row->update(['status' => BusinessBillTransaction::STATUS_VOIDED, 'cancel_note' => '', 'cancellation_date' => now()]);
            $this->ledger->adjustClientBalance(self::BUSINESSBP_CLIENT_ID, 'prefund', 'less', $fee, 24, 'Business Billpay Debit', $row->transaction_id);
            $this->ledger->adjustCardBalance($payee->ezkard_account_id, 'less', $totalAmount, 24, 'Void - Customers Payment', self::BUSINESSBP_CLIENT_ID, $row->transaction_id);
            EzkardTransaction::where('transaction_id', $transactionId)->update(['trans_status_id' => 1]);
        });

        return ['message' => 'Transaction has been voided successfully.'];
    }

    // ── DONATION ─────────────────────────────────────────────────────────────

    private function searchDonation(string $transactionId): array
    {
        $r = $this->rows->donationRow($transactionId);
        if (! $r) {
            return [];
        }

        return [[
            'transaction_id' => $transactionId,
            'transaction_type' => 'DONATION',
            'transaction_type_label' => self::TYPES['DONATION'],
            'customer_name' => $r->legal_name,
            'mobile' => $r->donor_mobile,
            'amount' => (float) $r->donation_amount,
            'timestamp' => $r->transaction_date,
            'status' => (int) $r->webpos_status === 1 ? 'voided' : 'active',
        ]];
    }

    /**
     * @throws ValidationException
     */
    private function voidDonation(string $transactionId): array
    {
        $r = $this->rows->donationRow($transactionId);
        if (! $r) {
            throw ValidationException::withMessages(['id' => ['Transaction not found.']]);
        }
        if ((int) $r->webpos_status === 1) {
            throw ValidationException::withMessages(['id' => ['This transaction has already been voided.']]);
        }

        $amount = (float) $r->donation_amount;

        DB::connection('mysuncash')->transaction(function () use ($r, $transactionId, $amount) {
            $this->ledger->adjustClientBalance((int) $r->merchant_id, 'prefund', 'add', $amount, 36, 'Void Donation to '.$r->legal_name, $transactionId);
            $this->ledger->adjustClientBalance((int) $r->charity_code, 'settlement', 'less', $amount, 35, 'Void Donation from '.$r->donor_name.' ('.$r->donor_mobile.')', $transactionId);
            WebposTransaction::where('transaction_type', 'DONATION')->where('transaction_id', $transactionId)->update(['status' => 1]);
        });

        return ['message' => 'Transaction has been voided successfully.'];
    }

    // ── CHECKCASHING ─────────────────────────────────────────────────────────

    private function searchCheckCashing(string $transactionId): array
    {
        $r = $this->rows->checkCashingRow($transactionId);
        if (! $r) {
            return [];
        }

        return [[
            'transaction_id' => $transactionId,
            'transaction_type' => 'CHECKCASHING',
            'transaction_type_label' => self::TYPES['CHECKCASHING'],
            'customer_name' => $r->customer_name,
            'mobile' => $r->mobile,
            'amount' => (float) $r->amount,
            'timestamp' => $r->transaction_date,
            'status' => (int) $r->webpos_status === 1 ? 'voided' : 'active',
        ]];
    }

    /**
     * @throws ValidationException
     */
    private function voidCheckCashing(string $transactionId): array
    {
        $r = $this->rows->checkCashingRow($transactionId);
        if (! $r) {
            throw ValidationException::withMessages(['id' => ['Transaction not found.']]);
        }
        if ((int) $r->webpos_status === 1) {
            throw ValidationException::withMessages(['id' => ['This transaction has already been voided.']]);
        }

        $amount = (float) $r->amount;

        DB::connection('mysuncash')->transaction(function () use ($r, $transactionId, $amount) {
            $this->ledger->adjustClientBalance(self::CHECKCASHING_CLIENT_ID, 'prefund', 'add', $amount, 53, 'Void CHECKCASHING - Debit', $transactionId);
            $this->ledger->adjustClientBalance((int) $r->merchant_id, 'settlement', 'less', $amount, 54, 'Void CHECKCASHING - Credit', $transactionId);
            WebposTransaction::where('transaction_type', 'CHECKCASHING')->where('transaction_id', $transactionId)->update(['status' => 1]);
        });

        return ['message' => 'Transaction has been voided successfully.'];
    }

    // ── SUNCASH_VOUCHER / UNIBUCKS_VOUCHER ───────────────────────────────────

    private function voucherRow(string $transactionId, string $model): ?object
    {
        /** @var class-string<MerchantVoucher|UniversalVoucher> $model */
        $table = (new $model)->getTable();

        return DB::connection('mysuncash')->table('ezkard_transactions as et')
            ->leftJoin('customers as c', 'c.ezkard_account_id', '=', 'et.ezkard_id')
            ->join('customer_transaction_histories as cth', 'cth.transaction_reference', '=', 'et.transaction_id')
            ->join("{$table} as mv", 'cth.ref_id', '=', 'mv.voucher_code')
            ->where('et.transaction_id', $transactionId)
            ->where('et.trans_type_id', 58)
            ->select('et.*', 'c.first_name', 'c.last_name', 'c.mobile', 'c.ezkard_account_id', 'mv.voucher_code', 'mv.status as voucher_status', 'cth.ref_id', 'cth.transaction_fee', 'cth.vat')
            ->first();
    }

    private function searchVoucher(string $transactionId, string $model, string $type): array
    {
        $r = $this->voucherRow($transactionId, $model);
        if (! $r) {
            return [];
        }

        $status = (int) $r->trans_status_id === 1 ? 'voided' : 'active';
        $statusLabel = $r->voucher_status !== 'ACTIVE' ? ucfirst(strtolower($r->voucher_status)) : ($status === 'voided' ? 'Voided' : 'Active');

        return [[
            'transaction_id' => $transactionId,
            'transaction_type' => $type,
            'transaction_type_label' => self::TYPES[$type],
            'customer_name' => trim(($r->first_name ?? '').' '.($r->last_name ?? '')) ?: null,
            'mobile' => $r->mobile,
            'amount' => (float) $r->amount,
            'timestamp' => $r->timestamp,
            'status' => $status,
            'status_label' => $statusLabel,
        ]];
    }

    /**
     * @throws ValidationException
     */
    private function voidVoucher(string $transactionId, string $model, string $type): array
    {
        $r = $this->voucherRow($transactionId, $model);
        if (! $r || (int) $r->trans_status_id === 1) {
            throw ValidationException::withMessages(['id' => ['Unable to void transaction.']]);
        }

        /** @var class-string<MerchantVoucher|UniversalVoucher> $model */
        $voided = $model::where('voucher_code', $r->voucher_code)->where('status', 'ACTIVE')
            ->update(['status' => 'VOIDED', 'update_date' => now()]);
        if (! $voided) {
            throw ValidationException::withMessages(['id' => ['This voucher has already been redeemed or voided.']]);
        }

        $totalAmount = (float) $r->amount + (float) ($r->transaction_fee ?? 0) + (float) ($r->vat ?? 0);
        $customer = $this->rows->customerByEzkardId($r->ezkard_account_id);

        DB::connection('mysuncash')->transaction(function () use ($r, $transactionId, $type, $totalAmount, $customer) {
            $this->ledger->adjustCardBalance($r->ezkard_account_id, 'add', $totalAmount, 59, $r->description.' - REFUND', null, $r->ref_id);
            $this->ledger->logCustomerHistory($customer, $r->ezkard_account_id, (string) $r->ref_id, $type, 'VOUCHER', $r->description.' - REFUND', $totalAmount, 'CREDIT');
            EzkardTransaction::where('transaction_id', $transactionId)->where('trans_type_id', 58)->update(['trans_status_id' => 1]);
        });

        return ['message' => 'Transaction has been voided successfully.'];
    }
}
