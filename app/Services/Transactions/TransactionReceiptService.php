<?php

namespace App\Services\Transactions;

use App\Models\ActivityLog;
use App\Models\Mysuncash\BusinessBillTransaction;
use App\Models\Mysuncash\EzkardAccount;
use App\Models\Mysuncash\EzkardTransaction;
use App\Models\User;
use App\Services\Notifications\InfobipSmsService;
use App\Services\Transactions\Support\TransactionRowFetcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/**
 * "Transactions > Resend Transaction Receipt" — legacy `Tools::
 * resend_receipt_management()` / `search_transaction_for_receipt()`, backed
 * by `Tools_model::search_transaction()`.
 *
 * Legacy's own UI dropdown only wires up 9 of the ~16 types this model's
 * search dispatch actually supports (`RELOAD, CASHOUT_MOBILE, CASHOUT_CODE,
 * SALE, MONEY_TRANSFER, BILLPAY, DONATION, TICKETS, TICKETS_MOVIE` — the
 * rest, e.g. `PHONE2PHONE`/`CUSTOMERSPAYMENT`/`BUSINESS_BILLPAY`, are only
 * reachable by POSTing the type directly, not through the visible <select>).
 * All of them are exposed here, matching the full search capability. Every
 * type this shares with Void Transaction (Money Transfer, Phone to Phone/
 * Store, Cashout by Code/Mobile, Business Billpay(+Store), Customer's
 * Payment, Donation, Check Cashing) reuses the identical row lookups from
 * `Support\TransactionRowFetcher` rather than re-querying them here.
 *
 * Legacy's "Send Receipt" (`Tools::send_receipt_via_text()`) renders an HTML
 * receipt to a static file and TEXTS a link to it via `settings::send_sms()`
 * — a real Infobip Advanced SMS API call. That call IS ported
 * (`Services\Notifications\InfobipSmsService`), but gated behind
 * `services.infobip.enabled` (default OFF) so this codebase never actually
 * sends an SMS until a deployment turns it on with real production
 * credentials — matching every other SMS-notification feature this session,
 * just with the real integration wired up and ready instead of skipped
 * outright. Legacy's static-file receipt hosting is replaced with a signed,
 * unauthenticated PDF route (`receipts.show`) the SMS links to.
 *
 * Also fixed vs legacy: `TICKETS_MOVIE`'s search never filtered by
 * `transaction_id` at all (`WHERE transaction_type='TICKETS_MOVIE'` with no
 * ID clause), so it always returned the first movie-ticket row regardless
 * of what was searched. This filters by the searched ID, matching the
 * screen's obvious intent.
 */
class TransactionReceiptService
{
    public const TYPES = [
        'RELOAD' => 'Load',
        'SALE' => 'Purchase',
        'ACTIVATION' => 'Activation',
        'MONEY_TRANSFER' => 'Money Transfer',
        'PHONE2PHONE' => 'Phone to Phone',
        'PHONE2STORE' => 'Phone to Store',
        'CASHOUT_CODE' => 'Cashout by Code',
        'CASHOUT_MOBILE' => 'Cashout by Mobile',
        'BILLPAY' => 'Billpay',
        'BUSINESS_BILLPAY' => 'Business Billpay',
        'BUSINESS_BILLPAY_STORE' => 'Business Billpay (Store)',
        'CUSTOMERSPAYMENT' => "Customer's Payment",
        'DONATION' => 'Donation',
        'CHECKCASHING' => 'Check Cashing',
        'TICKETS' => 'Events Ticket',
        'TICKETS_MOVIE' => 'Movie Ticket',
    ];

    public function __construct(
        private readonly TransactionRowFetcher $rows,
        private readonly InfobipSmsService $sms,
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

    /**
     * @throws ValidationException
     */
    public function search(string $transactionId, string $transactionType): array
    {
        if (! array_key_exists($transactionType, self::TYPES)) {
            throw ValidationException::withMessages(['transaction_type' => ['Select a valid transaction type.']]);
        }

        $rows = match ($transactionType) {
            'RELOAD', 'SALE', 'ACTIVATION' => $this->searchCardLedger($transactionId, $transactionType),
            'MONEY_TRANSFER' => $this->searchMoneyTransfer($transactionId),
            'PHONE2PHONE' => $this->searchPhoneToPhone($transactionId),
            'PHONE2STORE' => $this->searchPhoneToStore($transactionId),
            'CASHOUT_CODE' => $this->searchCashoutCode($transactionId),
            'CASHOUT_MOBILE' => $this->searchCashoutMobile($transactionId),
            'BILLPAY' => $this->searchBillpay($transactionId),
            'BUSINESS_BILLPAY' => $this->searchBusinessBillpay($transactionId, false),
            'BUSINESS_BILLPAY_STORE' => $this->searchBusinessBillpay($transactionId, true),
            'CUSTOMERSPAYMENT' => $this->searchCustomersPayment($transactionId),
            'DONATION' => $this->searchDonation($transactionId),
            'CHECKCASHING' => $this->searchCheckCashing($transactionId),
            'TICKETS' => $this->searchTickets($transactionId),
            'TICKETS_MOVIE' => $this->searchMovieTickets($transactionId),
        };

        return array_map(fn (array $row) => $this->present($row), $rows);
    }

    /**
     * The receipt-generation source: re-fetches the same row `search()`
     * would show (never trusts client-supplied amount/name), for rendering
     * as a PDF.
     *
     * @throws ValidationException
     */
    public function getReceipt(string $transactionId, string $transactionType): array
    {
        $rows = $this->search($transactionId, $transactionType);
        if (empty($rows)) {
            throw ValidationException::withMessages(['id' => ['Transaction not found.']]);
        }

        return $rows[0];
    }

    /**
     * Legacy's `send_receipt_via_text()` — texts a signed link to the PDF
     * receipt via Infobip. No-ops the actual send (but still builds the real
     * link and logs it) while `services.infobip.enabled` is off.
     *
     * @throws ValidationException
     */
    public function sendReceipt(string $transactionId, string $transactionType, string $mobile, string $actorId): array
    {
        if (! filled($mobile)) {
            throw ValidationException::withMessages(['mobile' => ['Enter a mobile number.']]);
        }

        $receipt = $this->getReceipt($transactionId, $transactionType);

        $link = URL::signedRoute('receipts.show', ['transactionType' => $transactionType, 'transactionId' => $transactionId]);
        $message = 'View your receipt from Suncash: '.$link;

        $result = $this->sms->send($mobile, $message);

        $description = $result['simulated']
            ? "Simulated receipt SMS for {$transactionId} to {$mobile} (Infobip disabled in this environment)."
            : 'Sent receipt SMS for '.$transactionId." to {$mobile}.";
        ActivityLog::recordAction(User::find($actorId), 'Resend Transaction Receipt', $result['simulated'] ? 'simulated' : 'sent', $description, null, null);

        return [
            'sent' => $result['sent'],
            'simulated' => $result['simulated'],
            'link' => $link,
            'message' => $result['simulated']
                ? 'Infobip SMS sending is disabled in this environment — no message was actually sent. The receipt link was generated and logged.'
                : ($result['sent'] ? 'Receipt has been sent via text.' : 'Failed to send the receipt via text.'),
        ];
    }

    // ── RELOAD / SALE / ACTIVATION ───────────────────────────────────────────

    private function searchCardLedger(string $transactionId, string $transactionType): array
    {
        $typeId = match ($transactionType) {
            'RELOAD' => 0,
            'SALE' => 1,
            'ACTIVATION' => 2,
        };

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

    // ── PHONE2PHONE / PHONE2STORE ────────────────────────────────────────────

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

    // ── CASHOUT_CODE / CASHOUT_MOBILE ────────────────────────────────────────

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

    // ── BILLPAY ──────────────────────────────────────────────────────────────

    private function searchBillpay(string $transactionId): array
    {
        if (str_starts_with($transactionId, '00')) {
            return [];
        }

        $r = DB::connection('mysuncash')->table('webpos_transaction as w')
            ->leftJoin('billpay_web_transactions as p', 'p.transaction_id', '=', 'w.transaction_id')
            ->where('w.transaction_type', 'BILLPAY')
            ->where('w.transaction_id', $transactionId)
            ->select('p.*', 'w.status as webpos_status', 'w.transaction_date')
            ->first();
        if (! $r) {
            return [];
        }

        return [[
            'transaction_id' => $transactionId,
            'transaction_type' => 'BILLPAY',
            'transaction_type_label' => self::TYPES['BILLPAY'],
            'customer_name' => $r->customer_name ?? $r->bill_account_name ?? null,
            'mobile' => $r->customer_mobile ?? null,
            'amount' => (float) ($r->bill_amount ?? 0),
            'timestamp' => $r->transaction_date,
            'status' => (int) $r->webpos_status === 1 ? 'voided' : 'active',
        ]];
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
            'customer_name' => $row->customer_name,
            'mobile' => $row->mobile_number,
            'amount' => (float) $row->amount,
            'timestamp' => $row->transaction_date,
            'status' => $row->status === 'V' ? 'voided' : 'active',
            'status_label' => $this->rows->businessBillpayStatusLabel($row->status),
        ]];
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

    // ── TICKETS ──────────────────────────────────────────────────────────────

    private function searchTickets(string $transactionId): array
    {
        $r = DB::connection('mysuncash')->table('webpos_transaction as w')
            ->leftJoin('ticket_transaction as t', 't.transaction_id', '=', 'w.transaction_id')
            ->leftJoin('ticket_transaction_detail as d', 'd.order_id', '=', 't.order_id')
            ->where('w.transaction_type', 'TICKETS')
            ->where('w.transaction_id', $transactionId)
            ->select('d.*', 'w.amount', 'w.status as webpos_status', 'w.transaction_date')
            ->first();
        if (! $r) {
            return [];
        }

        return [[
            'transaction_id' => $transactionId,
            'transaction_type' => 'TICKETS',
            'transaction_type_label' => self::TYPES['TICKETS'],
            'customer_name' => $r->name,
            'mobile' => $r->mobile,
            'amount' => (float) $r->amount,
            'timestamp' => $r->transaction_date,
            'status' => (int) $r->webpos_status === 1 ? 'voided' : 'active',
        ]];
    }

    // ── TICKETS_MOVIE ────────────────────────────────────────────────────────

    private function searchMovieTickets(string $transactionId): array
    {
        // Legacy never filters this branch by transaction_id at all — always
        // returning the first movie-ticket row in the table. Filtered here
        // to match the screen's obvious intent.
        $r = DB::connection('mysuncash')->table('movie_ticket_transaction as m')
            ->leftJoin('movie_ticket_transaction_detail as d', 'd.movie_ticket_id', '=', 'm.id')
            ->where('m.transaction_id', $transactionId)
            ->select('m.*', 'd.movie_name')
            ->first();
        if (! $r) {
            return [];
        }

        return [[
            'transaction_id' => $transactionId,
            'transaction_type' => 'TICKETS_MOVIE',
            'transaction_type_label' => self::TYPES['TICKETS_MOVIE'],
            'customer_name' => $r->movie_name,
            'mobile' => $r->mobile_number,
            'amount' => (float) $r->total,
            'timestamp' => $r->transaction_date,
            'status' => 'active',
        ]];
    }
}
