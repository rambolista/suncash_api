<?php

namespace App\Services\Transactions\Support;

use App\Models\Mysuncash\Customer;
use App\Models\Mysuncash\EzkardTransaction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only per-type transaction lookups shared by Void Transaction and
 * Resend Transaction Receipt — both need to find "the row for this
 * transaction_id + type" before doing anything else with it, and every
 * type they have in common (Money Transfer, Phone to Phone/Store, Cashout
 * by Code/Mobile, Business Billpay(+Store), Customer's Payment, Donation,
 * Check Cashing) uses the exact same joins to get there. Each method here
 * selects every column either consumer needs; a consumer that only needs a
 * subset (e.g. Resend Receipt never needs `terminal_id`) just ignores the
 * rest rather than the query being duplicated with a narrower column list.
 */
class TransactionRowFetcher
{
    public function customerByEzkardId(?int $ezkardId): ?Customer
    {
        return $ezkardId ? Customer::where('ezkard_account_id', $ezkardId)->first() : null;
    }

    /** The `ezkard_transactions` rows for a given transaction_id + trans_type_id (0=Load, 1=Sale, 2=Activation, ...). */
    public function cardLedgerTransactions(string $transactionId, int $typeId): Collection
    {
        return EzkardTransaction::where('transaction_id', $transactionId)
            ->where('trans_type_id', $typeId)
            ->orderByDesc('timestamp')
            ->get();
    }

    public function businessBillpayStatusLabel(?string $status): string
    {
        return match ($status) {
            'P' => 'Processed',
            'A', 'S' => 'Pending',
            'V' => 'Voided',
            default => 'Unknown',
        };
    }

    /**
     * `webpos_transaction.transaction_id` used to double as
     * `cashout_transactionsv3.id` (older rows, `reference` left blank/-1).
     * Newer rows carry a long POS-generated reference number in
     * `transaction_id` instead and moved the real cashout id into
     * `reference` — join on whichever one actually points at a cashout row,
     * or every money transfer since that change shows up here with blank
     * sender/beneficiary details and fails to void ("Invalid customer
     * record") even though the transaction is perfectly valid.
     */
    public function moneyTransferRow(string $transactionId): ?object
    {
        $w = DB::connection('mysuncash')->table('webpos_transaction')
            ->where('transaction_type', 'MONEY_TRANSFER')
            ->where('transaction_id', $transactionId)
            ->first();
        if (! $w) {
            return null;
        }

        $cashoutId = (filled($w->reference) && (int) $w->reference > 0) ? $w->reference : $transactionId;

        $cashout = DB::connection('mysuncash')->table('cashout_transactionsv3 as cr')
            ->leftJoin('cashout_transaction_detailsv3 as c', 'c.cashout_id', '=', 'cr.id')
            ->where('cr.id', $cashoutId)
            ->select('c.*', 'cr.cashout_reference', 'cr.id as cr_id')
            ->first();

        return (object) array_merge((array) ($cashout ?: []), [
            'merchant_id' => $w->merchant_id,
            'amount' => $w->amount,
            'webpos_status' => $w->status,
            'transaction_date' => $w->transaction_date,
            'terminal_id' => $w->terminal_id,
            'terminal_user_id' => $w->terminal_user_id,
        ]);
    }

    public function phoneToPhoneRow(string $transactionId): ?object
    {
        return DB::connection('mysuncash')->table('ezkard_transactions as et')
            ->leftJoin('customers as c', 'c.ezkard_account_id', '=', 'et.ezkard_id')
            ->leftJoin('customer_transaction_histories as cth', 'cth.description', '=', 'et.description')
            ->where('et.transaction_id', $transactionId)
            ->where('et.trans_type_id', 16)
            ->select('et.*', 'c.id as customer_id', 'c.first_name', 'c.last_name', 'c.mobile', 'c.ezkard_account_id', 'cth.transaction_reference', 'cth.transaction_fee', 'cth.vat')
            ->first();
    }

    public function phoneToPhoneReceiverRow(string $transactionId): ?object
    {
        return DB::connection('mysuncash')->table('ezkard_transactions as et')
            ->leftJoin('customers as c', 'c.ezkard_account_id', '=', 'et.ezkard_id')
            ->leftJoin('customer_transaction_histories as cth', 'cth.description', '=', 'et.description')
            ->where('et.transaction_id', $transactionId)
            ->where('et.trans_type_id', 17)
            ->where('et.trans_status_id', '!=', 1)
            ->select('et.*', 'c.ezkard_account_id', 'cth.transaction_reference', 'cth.transaction_fee', 'cth.vat')
            ->first();
    }

    public function phoneToStoreRow(string $transactionId): ?object
    {
        return DB::connection('mysuncash')->table('ezkard_transactions as et')
            ->leftJoin('customers as c', 'c.ezkard_account_id', '=', 'et.ezkard_id')
            ->leftJoin('customer_transaction_histories as cth', 'cth.description', '=', 'et.description')
            ->where('et.transaction_id', $transactionId)
            ->where('et.trans_type_id', 47)
            ->select('et.*', 'c.first_name', 'c.last_name', 'c.mobile', 'c.ezkard_account_id', 'cth.transaction_reference', 'cth.transaction_fee', 'cth.vat')
            ->first();
    }

    /** Same `transaction_id` vs `reference` split as {@see moneyTransferRow()} — CASHOUT_CODE redemptions use the identical webpos_transaction shape. */
    public function cashoutCodeRow(string $transactionId): ?object
    {
        $w = DB::connection('mysuncash')->table('webpos_transaction')
            ->where('transaction_type', 'CASHOUT_CODE')
            ->where('transaction_id', $transactionId)
            ->first();
        if (! $w) {
            return null;
        }

        $cashoutId = (filled($w->reference) && (int) $w->reference > 0) ? $w->reference : $transactionId;

        $cashout = DB::connection('mysuncash')->table('cashout_transactionsv3 as cr')
            ->leftJoin('cashout_transaction_detailsv3 as c', 'c.cashout_id', '=', 'cr.id')
            ->where('cr.id', $cashoutId)
            ->select('c.*', 'cr.cashout_reference', 'cr.id as cr_id')
            ->first();

        return (object) array_merge((array) ($cashout ?: []), [
            'merchant_id' => $w->merchant_id,
            'amount' => $w->amount,
            'webpos_status' => $w->status,
            'transaction_date' => $w->transaction_date,
            'terminal_id' => $w->terminal_id,
            'terminal_user_id' => $w->terminal_user_id,
        ]);
    }

    public function cashoutMobileRow(string $transactionId): ?object
    {
        return DB::connection('mysuncash')->table('webpos_transaction as w')
            ->leftJoin('client_transactions as c', 'c.ref_trans_id', '=', 'w.transaction_id')
            ->leftJoin('ezkard_transactions as e', 'e.transaction_id', '=', 'c.ref_trans_id')
            ->leftJoin('customers as cs', 'cs.ezkard_account_id', '=', 'e.ezkard_id')
            ->where('w.transaction_type', 'CASHOUT_MOBILE')
            ->where('w.transaction_id', $transactionId)
            ->where('e.trans_type_id', 20)
            ->select('w.merchant_id', 'w.amount', 'w.fee_amount', 'w.status as webpos_status', 'w.transaction_date', 'w.terminal_id', 'w.terminal_user_id', 'e.ezkard_id', 'cs.id as customer_id', 'cs.first_name', 'cs.last_name', 'cs.mobile')
            ->first();
    }

    /** Resolves BUSINESS_BILLPAY (via its ezkard_transactions link, trans_type_id=63) or BUSINESS_BILLPAY_STORE (direct) down to its `business_bill_transaction` row. */
    public function businessBillpayRow(string $transactionId, bool $store): ?object
    {
        if (! $store) {
            $link = DB::connection('mysuncash')->table('ezkard_transactions')
                ->where('transaction_id', $transactionId)->where('trans_type_id', 63)->first();
            if (! $link) {
                return null;
            }
            $transactionId = (string) $link->reference_id;
        }

        return DB::connection('mysuncash')->table('business_bill_transaction as bbt')
            ->where('bbt.transaction_id', (int) $transactionId)
            ->first();
    }

    /** The `ezkard_transactions.reference_id` a CUSTOMERSPAYMENT transaction_id points at, into `business_bill_transaction`. */
    public function customersPaymentLink(string $transactionId): ?int
    {
        $referenceId = DB::connection('mysuncash')->table('ezkard_transactions')->where('transaction_id', $transactionId)->value('reference_id');

        return ($referenceId !== null && is_numeric($referenceId)) ? (int) $referenceId : null;
    }

    public function donationRow(string $transactionId): ?object
    {
        return DB::connection('mysuncash')->table('webpos_transaction as w')
            ->leftJoin('donate_transactions as d', 'd.settlement_transaction_id', '=', 'w.transaction_id')
            ->leftJoin('clients as c', 'd.charity_code', '=', 'c.id')
            ->where('w.transaction_type', 'DONATION')
            ->where('w.transaction_id', $transactionId)
            ->select('d.*', 'c.legal_name', 'w.status as webpos_status', 'w.transaction_date', 'w.merchant_id', 'w.terminal_id', 'w.terminal_user_id')
            ->first();
    }

    public function checkCashingRow(string $transactionId): ?object
    {
        return DB::connection('mysuncash')->table('webpos_transaction as w')
            ->leftJoin('check_cashing_transaction as c', 'c.transaction_id', '=', 'w.transaction_id')
            ->where('w.transaction_type', 'CHECKCASHING')
            ->where('w.transaction_id', $transactionId)
            ->select('w.merchant_id', 'w.amount', 'w.status as webpos_status', 'w.transaction_date', 'c.customer_name', 'c.mobile')
            ->first();
    }
}
