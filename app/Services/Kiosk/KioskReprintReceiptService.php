<?php

namespace App\Services\Kiosk;

use App\Models\Mysuncash\WebLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Kiosk > Reprint Receipt" (legacy `fastpay::kiosk_receipt()` /
 * `search_trx_receipt()` / `reprint_receipt()` /
 * `fastpay_kiosk_model::get_transaction_receipt()`). Search a kiosk
 * terminal transaction by type + ID, then reprint its receipt.
 *
 * Legacy is print-only — there is no "send"/SMS/email action anywhere in
 * this feature, only a local browser print (hidden iframe + `window.
 * print()`) and an audit-log write on every reprint
 * (`web_logs.log_type='KIOSK_REPRINT_RECEIPT'`). Both are replicated as-is;
 * no notification capability is added since legacy never had one here.
 *
 * Every branch shares the same underlying shape — a receipt is a flat list
 * of {label, text} line items rendered under a brand/terminal header —
 * matching legacy's own `generate_reprint_receipt()`. The 16 legacy
 * transaction types collapse into these `TYPES` and are dispatched over
 * `webpos_transaction_kiosk` (the kiosk-terminal transaction ledger,
 * distinct from the general/card-ledger transactions
 * `TransactionReceiptService` already covers for Resend Transaction
 * Receipt — no data overlap between the two).
 *
 * QR codes: legacy fetches a rendered PNG from a live third-party service
 * (quickchart.io) on every request and embeds it as base64. This returns
 * the raw `qr_payload` string instead and lets the frontend render the QR
 * client-side (this app already has a QR library for 2FA setup) — same
 * data, no live external HTTP call on every reprint.
 */
class KioskReprintReceiptService
{
    public const TYPES = [
        'VOUCHER' => 'Voucher',
        'LOAD_MOBILEWALLET_SANDDOLLAR' => 'Load Mobile Wallet SandDollar',
        'LOAD_SANDDOLLAR' => 'Load SandDollar',
        'SEND_SANDDOLLAR' => 'Send SandDollar',
        'MOBILE_TOPUP' => 'Mobile Topup',
        'BILLPAY' => 'Billpay',
        'CASHOUT' => 'SunCash Withdrawal',
        'LOAD' => 'SunCash Deposit',
        'BANK_DEPOSIT' => 'Bank Deposit',
        'LOCAL_GC' => 'Local Giftcard',
        'MGODIGITALSALES' => 'International Giftcard',
        'GH_DEPOSIT' => 'Gaming House Deposit',
        'GH_WITHDRAW' => 'Gaming House Withdraw',
        'CARD_WITHDRAWAL' => 'Card Withdrawal',
        'WU_SEND' => 'Western Union Send',
        'WU_RECEIVE' => 'Western Union Receive',
    ];

    private function db(): \Illuminate\Database\Connection
    {
        return DB::connection('mysuncash');
    }

    /**
     * @throws ValidationException
     */
    private function assertValidType(string $transactionType): void
    {
        if (! array_key_exists($transactionType, self::TYPES)) {
            throw ValidationException::withMessages(['transaction_type' => ['Select a valid transaction type.']]);
        }
    }

    /**
     * @throws ValidationException
     */
    public function search(string $transactionId, string $transactionType): array
    {
        $this->assertValidType($transactionType);

        $found = $this->fetch($transactionId, $transactionType);
        if (! $found) {
            throw ValidationException::withMessages(['transaction_id' => ['No records found.']]);
        }

        [$row] = $found;

        return [
            'settlement_time' => $row['settlement_time'] ?? null,
            'terminal' => $row['terminal'] ?? null,
            'location' => $row['location'] ?? null,
            'brand' => $row['brand'] ?? self::TYPES[$transactionType],
            'transaction_id' => $row['transaction_id'] ?? $transactionId,
            'customer_number' => $row['customer_number'] ?? null,
            'total_amount_receive' => round((float) ($row['total_amount'] ?? 0), 2),
            'total_fees' => round((float) ($row['fee_amount'] ?? 0) + (float) ($row['vat_amount'] ?? 0), 2),
            'product_amount' => round((float) ($row['amount'] ?? 0), 2),
        ];
    }

    /**
     * @throws ValidationException
     */
    public function receipt(string $transactionId, string $transactionType, string $actorId, string $actorIp): array
    {
        $this->assertValidType($transactionType);

        $found = $this->fetch($transactionId, $transactionType);
        if (! $found) {
            throw ValidationException::withMessages(['transaction_id' => ['Unable to reprint receipt.']]);
        }

        [, $receipt] = $found;

        WebLog::create([
            'user_id' => $actorId,
            'updated_by' => $actorId,
            'log_type' => 'KIOSK_REPRINT_RECEIPT',
            'data' => $transactionId.'/'.$transactionType,
            'user_ip_address' => $actorIp,
            'cloudflare_ip_address' => $actorIp,
            'web_channel' => 'admin',
        ]);

        return $receipt;
    }

    /** @return array{0: array, 1: array}|null [$rawRow, $receipt] */
    private function fetch(string $transactionId, string $transactionType): ?array
    {
        return match (true) {
            $transactionType === 'VOUCHER' => $this->voucher($transactionId),
            in_array($transactionType, ['LOAD_MOBILEWALLET_SANDDOLLAR', 'LOAD_SANDDOLLAR', 'SEND_SANDDOLLAR'], true) => $this->sanddollar($transactionId, $transactionType),
            $transactionType === 'MOBILE_TOPUP' => $this->mobileTopup($transactionId),
            $transactionType === 'BILLPAY' => $this->billpay($transactionId),
            in_array($transactionType, ['CASHOUT', 'LOAD'], true) => $this->cashoutOrLoad($transactionId, $transactionType),
            $transactionType === 'BANK_DEPOSIT' => $this->bankDeposit($transactionId),
            $transactionType === 'LOCAL_GC' => $this->localGiftcard($transactionId),
            $transactionType === 'MGODIGITALSALES' => $this->internationalGiftcard($transactionId),
            in_array($transactionType, ['GH_DEPOSIT', 'GH_WITHDRAW'], true) => $this->gamingHouse($transactionId, $transactionType),
            $transactionType === 'CARD_WITHDRAWAL' => $this->cardWithdrawal($transactionId),
            $transactionType === 'WU_SEND' => $this->westernUnionSend($transactionId),
            $transactionType === 'WU_RECEIVE' => $this->westernUnionReceive($transactionId),
            default => null,
        };
    }

    private function lines(array $items): array
    {
        return array_map(fn ($item) => ['label' => $item[1], 'text' => $item[0]], $items);
    }

    private function decryptVoucherPin(string $pin): ?string
    {
        if (is_numeric($pin)) {
            return $pin;
        }

        $key = (string) config('services.voucher.crypt_key');
        $iv = base64_decode((string) config('services.voucher.iv'));
        $decrypted = openssl_decrypt(base64_decode($pin), 'aes-128-cbc', $key, OPENSSL_RAW_DATA, $iv);

        return $decrypted !== false && $decrypted !== '' ? $decrypted : null;
    }

    // ── VOUCHER ──────────────────────────────────────────────────────────────

    private function voucher(string $transactionId): ?array
    {
        $row = $this->db()->table('webpos_transaction_kiosk as wtk')
            ->leftJoin('universal_vouchers as uv', function ($join) {
                $join->on('uv.voucher_code', '=', 'wtk.trans_ref_id')->where('wtk.transaction_type', 'VOUCHER');
            })
            ->leftJoin('merchant_vouchers as mv', function ($join) {
                $join->on('mv.voucher_code', '=', 'wtk.trans_ref_id')->where('wtk.transaction_type', 'VOUCHER');
            })
            ->join('kiosk_terminal as ktl', 'ktl.id', '=', 'wtk.terminal_id')
            ->where('wtk.transaction_id', $transactionId)
            ->where('wtk.status', 0)
            ->where('wtk.transaction_type', 'VOUCHER')
            ->whereNotIn('wtk.trans_ref_id', ['', '-1'])
            ->selectRaw("wtk.transaction_date AS settlement_time,
                IF(mv.voucher_code IS NOT NULL, 'SUNCASH VOUCHER', IF(uv.voucher_product_id = '3', 'CREDIT VOUCHER', 'UNIBUCKS VOUCHER')) AS brand,
                IF(mv.voucher_code IS NOT NULL, mv.pin, uv.pin) AS voucher_pin,
                wtk.transaction_id AS transaction_id,
                IF(mv.voucher_code IS NOT NULL, mv.mobile, uv.receiver_mobile) AS receiver_mobile,
                wtk.trans_ref_id AS voucher_code,
                wtk.trans_ref_id AS customer_number,
                wtk.amount, wtk.fee_amount, wtk.vat_amount, wtk.total_amount,
                ktl.location, ktl.name AS terminal")
            ->first();

        if (! $row) {
            return null;
        }

        $data = (array) $row;
        $pin = $this->decryptVoucherPin((string) $data['voucher_pin']);

        if ($data['brand'] === 'CREDIT VOUCHER') {
            return [$data, [
                'brand' => $data['brand'],
                'terminal' => $data['terminal'],
                'lines' => $this->lines([
                    [$data['settlement_time'], 'Settlement Time'],
                    [$data['terminal'], 'Terminal ID'],
                    ['CREDIT VOUCHER', 'Service'],
                    [$data['total_amount'], 'Voucher Value'],
                    [$data['fee_amount'], 'Fee'],
                    [$data['vat_amount'], 'VAT'],
                    [$data['voucher_code'], 'Voucher Code'],
                    [$pin, 'Voucher PIN'],
                ]),
                'footer' => ['Redeem your credit voucher into', 'the SunCash App, at pay.mysuncash.com', 'or any SunCash Store.'],
            ]];
        }

        return [$data, [
            'brand' => $data['brand'],
            'terminal' => $data['terminal'],
            'lines' => $this->lines([
                [$data['settlement_time'], 'Settlement Time'],
                [$data['terminal'], 'Terminal ID'],
                [$data['receiver_mobile'], 'Receiver Phone'],
                [$data['amount'], 'Voucher Amount'],
                [$data['fee_amount'], 'Fee'],
                [$data['vat_amount'], 'VAT'],
                [$data['total_amount'], 'Total Deposited'],
                [$data['brand'], 'Voucher Brand'],
                ['VOUCHER', 'Service'],
                [$data['transaction_id'], 'Ref. Number'],
                [$data['voucher_code'], 'Voucher Code'],
                [$pin, 'Voucher Pin'],
            ]),
            'qr_payload' => $data['voucher_code'].'/'.$pin,
        ]];
    }

    // ── LOAD_MOBILEWALLET_SANDDOLLAR / LOAD_SANDDOLLAR / SEND_SANDDOLLAR ────

    private function sanddollar(string $transactionId, string $transactionType): ?array
    {
        $row = $this->db()->table('webpos_transaction_kiosk as wtk')
            ->join('kiosk_terminal as ktl', 'ktl.id', '=', 'wtk.terminal_id')
            ->join('sanddollar_trail as st', function ($join) {
                $join->on('st.transaction_id', '=', 'wtk.transaction_id')
                    ->whereIn('wtk.transaction_type', ['LOAD_MOBILEWALLET_SANDDOLLAR', 'LOAD_SANDDOLLAR', 'SEND_SANDDOLLAR']);
            })
            ->where('wtk.transaction_id', $transactionId)
            ->where('wtk.status', 0)
            ->whereIn('wtk.transaction_type', ['LOAD_MOBILEWALLET_SANDDOLLAR', 'LOAD_SANDDOLLAR', 'SEND_SANDDOLLAR'])
            ->selectRaw("wtk.transaction_date AS settlement_time, wtk.transaction_id,
                IF(LOCATE('@sanddollar.bs', st.customer) > 0, st.customer, CONCAT(st.customer,'@sanddollar.bs')) AS customer_name,
                wtk.amount, wtk.fee_amount, wtk.vat_amount, wtk.total_amount,
                ktl.location, ktl.name AS terminal,
                IF(wtk.transaction_type = 'SEND_SANDDOLLAR', IF(wtk.transaction_type = 'LOAD_SANDDOLLAR', 'LOAD SANDDOLLAR', 'SEND SANDDOLLAR'), 'LOAD MOBILE WALLET SANDDOLLAR') AS brand")
            ->first();

        if (! $row) {
            return null;
        }

        $data = (array) $row;

        return [$data, [
            'brand' => $data['brand'],
            'terminal' => $data['terminal'],
            'lines' => $this->lines([
                [$data['settlement_time'], 'Settlement Time'],
                [$data['terminal'], 'Terminal ID'],
                [$data['customer_name'], 'Receiver Name'],
                [$data['fee_amount'], 'Fee'],
                [$data['vat_amount'], 'VAT'],
                [$data['total_amount'], 'Total Deposited'],
                [$data['amount'], 'Receiver Gets'],
                [$data['transaction_id'], 'Ref. Number'],
                ['Sent', 'Status'],
                [preg_match('/sun/', (string) $data['customer_name']) ? 'Suncash' : 'Sanddollar', 'Service'],
            ]),
        ]];
    }

    // ── MOBILE_TOPUP ─────────────────────────────────────────────────────────

    private function mobileTopup(string $transactionId): ?array
    {
        $row = $this->db()->table('webpos_transaction_kiosk as wtk')
            ->join('kiosk_terminal as ktl', 'ktl.id', '=', 'wtk.terminal_id')
            ->leftJoin('mobile_topup_transactions as mtt', function ($join) {
                $join->on('mtt.id', '=', 'wtk.reference_id')->where('wtk.transaction_type', 'KioskTopup');
            })
            ->where('wtk.transaction_id', $transactionId)
            ->where('wtk.status', 0)
            ->where('wtk.transaction_type', 'KioskTopup')
            ->selectRaw("wtk.transaction_date AS settlement_time, wtk.transaction_id,
                IF(mtt.provider = 'paynation', 'INTERNATIONAL TOPUP', IF(mtt.provider = 'aliv', 'ALIV TOPUP', 'BTC TOPUP')) AS brand,
                IF(mtt.provider = 'paynation', 'INTERNATIONAL', IF(mtt.provider = 'aliv', 'ALIV', 'BTC')) AS provider,
                mtt.mobile_number AS customer_number,
                wtk.amount, wtk.fee_amount, wtk.vat_amount, wtk.total_amount,
                ktl.location, ktl.name AS terminal, mtt.provider_ref_number")
            ->first();

        if (! $row) {
            return null;
        }

        $data = (array) $row;

        return [$data, [
            'brand' => $data['brand'],
            'terminal' => $data['terminal'],
            'lines' => $this->lines([
                [$data['settlement_time'], 'Settlement Time'],
                [$data['terminal'], 'Terminal ID'],
                [$data['provider'], 'Provider'],
                ['TOP UP', 'Product'],
                [$data['customer_number'], 'Mobile Number'],
                [$data['amount'], 'Amount'],
                ['Mobile Topup', 'Service'],
                [$data['provider_ref_number'], 'Provider Ref. Number'],
                [$data['transaction_id'], 'transaction ID'],
            ]),
        ]];
    }

    // ── BILLPAY ──────────────────────────────────────────────────────────────

    private function billpay(string $transactionId): ?array
    {
        $row = $this->db()->table('webpos_transaction_kiosk as wtk')
            ->join('kiosk_terminal as ktl', 'ktl.id', '=', 'wtk.terminal_id')
            ->join('billpay_transactions as bt', function ($join) {
                $join->on('bt.settlement_transaction_id', '=', 'wtk.transaction_id')->where('wtk.transaction_type', 'BILLPAY');
            })
            ->leftJoin('kiosk_utility_accounts as kua', 'kua.bill_account_no', '=', 'bt.bill_account_no')
            ->where('wtk.transaction_id', $transactionId)
            ->where('wtk.status', 0)
            ->where('wtk.transaction_type', 'BILLPAY')
            ->selectRaw("wtk.transaction_date AS settlement_time, wtk.transaction_id,
                CONCAT(bt.biller_code, ' BILLPAY') AS brand,
                bt.biller_code AS company, bt.bill_account_no AS account_no, bt.bill_account_no AS customer_number,
                kua.bill_account_name AS account_name,
                wtk.amount, wtk.fee_amount, wtk.vat_amount, wtk.total_amount,
                ktl.location, ktl.name AS terminal")
            ->first();

        if (! $row) {
            return null;
        }

        $data = (array) $row;

        return [$data, [
            'brand' => $data['brand'],
            'terminal' => $data['terminal'],
            'lines' => $this->lines([
                [$data['settlement_time'], 'Settlement Time'],
                [$data['terminal'], 'Terminal ID'],
                [$data['transaction_id'], 'Transaction ID'],
                [$data['company'], 'Utility Company'],
                [$data['account_no'], 'Account Number'],
                [$data['account_name'], 'Account Name'],
                [$data['fee_amount'], 'Fee'],
                [$data['vat_amount'], 'Vat'],
                [$data['amount'], 'Amount'],
                [$data['brand'], 'Service'],
                [$data['transaction_id'], 'Ref. Number'],
            ]),
        ]];
    }

    // ── CASHOUT / LOAD ───────────────────────────────────────────────────────

    private function cashoutOrLoad(string $transactionId, string $transactionType): ?array
    {
        $row = $this->db()->table('webpos_transaction_kiosk as wtk')
            ->join('kiosk_terminal as ktl', 'ktl.id', '=', 'wtk.terminal_id')
            ->join('customers as c', 'c.id', '=', 'wtk.customer_id')
            ->join('customer_transaction_histories as cth', function ($join) {
                $join->on('cth.transaction_reference', '=', 'wtk.trans_ref_id')
                    ->whereIn('wtk.transaction_type', ['CASHOUT', 'LOAD'])
                    ->where('cth.channel', 'Kiosk');
            })
            ->where('wtk.transaction_id', $transactionId)
            ->where('wtk.status', 0)
            ->whereIn('wtk.transaction_type', ['CASHOUT', 'LOAD'])
            ->selectRaw("wtk.transaction_date AS settlement_time, wtk.transaction_id, wtk.trans_ref_id,
                c.mobile AS customer_number, CONCAT(c.first_name, ' ', c.last_name) AS customer_name,
                wtk.amount, wtk.fee_amount, wtk.vat_amount, wtk.total_amount,
                ktl.location, ktl.name AS terminal,
                cth.running_balance AS new_balance,
                cth.running_balance - wtk.total_amount AS old_balance,
                IF(wtk.transaction_type = 'CASHOUT', 'SUNCASH WITHDRAWAL', 'SUNCASH DEPOSIT') AS brand")
            ->first();

        if (! $row) {
            return null;
        }

        $data = (array) $row;

        if ($transactionType === 'CASHOUT') {
            $oldBalance = round((float) $data['new_balance'] + (float) $data['total_amount'], 2);

            return [$data, [
                'brand' => $data['brand'],
                'terminal' => $data['terminal'],
                'lines' => $this->lines([
                    [$data['settlement_time'], 'Settlement Time'],
                    [$data['terminal'], 'Terminal ID'],
                    [$data['customer_name'], 'Customer Name'],
                    [$data['fee_amount'], 'Fee'],
                    [$data['vat_amount'], 'VAT'],
                    [$data['amount'], 'Total Withdrawn'],
                    [$data['total_amount'], 'Amount Paid'],
                    [$oldBalance, 'Old Balance'],
                    [$data['new_balance'], 'New Balance'],
                    [$data['brand'], 'Service'],
                    [$data['transaction_id'], 'Ref. Number'],
                ]),
            ]];
        }

        $oldBalance = (float) $data['new_balance'] > (float) $data['total_amount']
            ? round((float) $data['new_balance'] - (float) $data['total_amount'], 2)
            : round((float) $data['total_amount'] - (float) $data['new_balance'], 2);

        return [$data, [
            'brand' => $data['brand'],
            'terminal' => $data['terminal'],
            'lines' => $this->lines([
                [$data['settlement_time'], 'Settlement Time'],
                [$data['terminal'], 'Terminal ID'],
                [$data['customer_name'], 'Customer Name'],
                [$data['fee_amount'], 'Fee'],
                [$data['vat_amount'], 'VAT'],
                [$data['amount'], 'Total Deposited'],
                [$oldBalance, 'Old Balance'],
                [$data['new_balance'], 'New Balance'],
                [$data['brand'], 'Service'],
                [$data['transaction_id'], 'Ref. Number'],
            ]),
        ]];
    }

    // ── BANK_DEPOSIT ─────────────────────────────────────────────────────────

    private function bankDeposit(string $transactionId): ?array
    {
        $row = $this->db()->table('customer_settlements as cs')
            ->join('kiosk_bank_accounts as kb', function ($join) {
                $join->on(function ($on) {
                    $on->on('kb.customer_number', '=', 'cs.customer_number')->on('cs.kiosk_banked_account_id', '=', 'kb.id');
                })->orOn(function ($on) {
                    $on->where('cs.kiosk_banked_account_id', '-1')->on('kb.customer_number', '=', 'cs.customer_number');
                });
            })
            ->join('webpos_transaction_kiosk as wtk', function ($join) {
                $join->on('wtk.reference_id', '=', 'cs.id')->where('cs.channel', 'Kiosk');
            })
            ->join('kiosk_terminal as ktl', 'ktl.id', '=', 'wtk.terminal_id')
            ->where('wtk.transaction_id', $transactionId)
            ->where('wtk.transaction_type', 'BANK_DEPOSIT')
            ->selectRaw("wtk.transaction_date AS settlement_time, wtk.transaction_id, wtk.trans_ref_id, wtk.reference_id,
                wtk.amount, wtk.fee_amount, wtk.vat_amount, wtk.total_amount,
                ktl.location, ktl.name AS terminal, 'BANK DEPOSIT' AS brand,
                kb.account_number, kb.account_type, cs.withdrawal_type AS delivery_type,
                wtk.fee_amount AS delivery_fee, kb.customer_name, kb.customer_number, kb.branch_name, kb.bank_name")
            ->first();

        if (! $row) {
            return null;
        }

        $data = (array) $row;
        $deliveryType = $data['delivery_type'] === 'express' ? 'EXPRESS DELIVERY - 1 business day' : 'STANDARD DELIVERY - 3 business days';
        $deliveryFee = (float) $data['fee_amount'] + (float) $data['vat_amount'];

        return [$data, [
            'brand' => $data['brand'],
            'terminal' => $data['terminal'],
            'lines' => $this->lines([
                [$data['settlement_time'], 'Settlement Time'],
                [$data['terminal'], 'Terminal ID'],
                ['', 'Recipient Info'],
                ['  '.$data['account_number'], 'Account Number'],
                ['  '.$data['account_type'], 'Account Type'],
                ['  '.$data['branch_name'], 'Bank Branch'],
                ['  '.$data['bank_name'], 'Bank Name'],
                ['  '.$deliveryType, 'Deliver Type'],
                ['  '.$deliveryFee, 'Deliver Fee'],
                [$data['total_amount'], 'Amount Deposited'],
                [$data['amount'], 'Recipient Gets'],
                ['BANK DEPOSIT', 'Service'],
                [$data['transaction_id'], 'Cashout Ref. Number'],
            ]),
        ]];
    }

    // ── LOCAL_GC ─────────────────────────────────────────────────────────────

    private function localGiftcard(string $transactionId): ?array
    {
        $row = $this->db()->table('webpos_transaction_kiosk as wtk')
            ->join('giftcard_transactions as uv', function ($join) {
                $join->on('uv.code', '=', 'wtk.trans_ref_id')->where('wtk.transaction_type', 'LOCAL_GC');
            })
            ->join('kiosk_terminal as ktl', 'ktl.id', '=', 'wtk.terminal_id')
            ->where(function ($query) use ($transactionId) {
                $query->where('wtk.transaction_id', $transactionId)->orWhere('uv.transaction_reference', $transactionId);
            })
            ->where('wtk.status', 0)
            ->where('wtk.transaction_type', 'LOCAL_GC')
            ->selectRaw("wtk.transaction_date AS settlement_time, uv.product_name AS product_name, 'Local Giftcard' AS brand,
                uv.pin AS voucher_pin, wtk.transaction_id AS transaction_id, wtk.reference_id AS receiver_mobile,
                wtk.trans_ref_id AS customer_number,
                wtk.amount, wtk.fee_amount, wtk.vat_amount, wtk.total_amount,
                ktl.location, ktl.name AS terminal, uv.qrcode, uv.code AS voucher_code, uv.transaction_reference")
            ->first();

        if (! $row) {
            return null;
        }

        $data = (array) $row;
        $pin = $this->decryptVoucherPin((string) $data['voucher_pin']);

        return [$data, [
            'brand' => $data['brand'],
            'terminal' => $data['terminal'],
            'lines' => $this->lines([
                [$data['settlement_time'], 'Settlement Time'],
                [$data['terminal'], 'Terminal ID'],
                [$data['receiver_mobile'], 'Receiver Phone'],
                [$data['amount'], 'Voucher Amount'],
                [$data['fee_amount'], 'Fee'],
                [$data['vat_amount'], 'VAT'],
                [$data['total_amount'], 'Total Deposited'],
                [$data['product_name'], 'Voucher Brand'],
                ['GIFTCARD', 'Service'],
                [$data['transaction_reference'], 'Ref. Number'],
                [$data['voucher_code'], 'Voucher Code'],
                [$pin, 'Voucher Pin'],
            ]),
            'qr_payload' => $data['qrcode'],
        ]];
    }

    // ── MGODIGITALSALES (international giftcards) ──────────────────────────

    private function internationalGiftcard(string $transactionId): ?array
    {
        $row = $this->db()->table('webpos_transaction_kiosk as wtk')
            ->join('mgo_giftcard_transactions as uv', function ($join) {
                $join->on('uv.transaction_id', '=', 'wtk.id')
                    ->where('wtk.transaction_type', 'MGODIGITALSALES')
                    ->where('uv.source', 'KIOSK')
                    ->where('uv.type', '!=', '-1');
            })
            ->join('kiosk_terminal as ktl', 'ktl.id', '=', 'wtk.terminal_id')
            ->where(function ($query) use ($transactionId) {
                $query->where('wtk.transaction_id', $transactionId)->orWhere('uv.ref_id', $transactionId);
            })
            ->where('wtk.status', 0)
            ->where('wtk.transaction_type', 'MGODIGITALSALES')
            ->where('uv.status', 'PROCESSED')
            ->selectRaw("wtk.transaction_date AS settlement_time, uv.giftcard_type AS product_name, 'International Gift Cards' AS brand,
                wtk.transaction_id, uv.receiver_mobile, uv.receiver_name AS customer_name, uv.giftcard_code AS customer_number,
                wtk.amount, wtk.fee_amount, wtk.vat_amount, wtk.total_amount,
                ktl.location, ktl.name AS terminal,
                uv.giftcard_code AS voucher_code, uv.ref_id AS transaction_reference, uv.type")
            ->first();

        if (! $row) {
            return null;
        }

        $data = (array) $row;
        $pin = $data['type'] === 'Pin' ? $data['voucher_code'] : '';
        $code = $data['type'] === 'Code' ? $data['voucher_code'] : '';
        $qrPayload = $data['type'] === 'linkcodeURL' ? $data['voucher_code'] : null;

        return [$data, [
            'brand' => $data['brand'],
            'terminal' => $data['terminal'],
            'lines' => $this->lines([
                [$data['settlement_time'], 'Settlement Time'],
                [$data['terminal'], 'Terminal ID'],
                [$data['receiver_mobile'], 'Receiver Phone'],
                [$data['amount'], 'Voucher Amount'],
                [$data['fee_amount'], 'Fee'],
                [$data['vat_amount'], 'VAT'],
                [$data['total_amount'], 'Total Deposited'],
                [$data['product_name'], 'Voucher Brand'],
                ['GIFTCARD', 'Service'],
                [$data['transaction_reference'], 'Ref. Number'],
                [$code, 'Voucher Code'],
                [$pin, 'Voucher Pin'],
            ]),
            'qr_payload' => $qrPayload,
        ]];
    }

    // ── GH_DEPOSIT / GH_WITHDRAW (gaming house) ─────────────────────────────

    private function gamingHouse(string $transactionId, string $transactionType): ?array
    {
        $legacyType = $transactionType === 'GH_DEPOSIT' ? 'GAMINGHOUSE_DEPOSIT' : 'GAMINGHOUSE_WITHDRAW';
        $brand = $transactionType === 'GH_DEPOSIT' ? 'Gaming Deposit' : 'Gaming Withdraw';

        $row = $this->db()->table('webpos_transaction_kiosk as wtk')
            ->join('kiosk_terminal as ktl', 'ktl.id', '=', 'wtk.terminal_id')
            ->where(function ($query) use ($transactionId) {
                $query->where('wtk.transaction_id', $transactionId)->orWhere('wtk.trans_ref_id', $transactionId);
            })
            ->where('wtk.status', 0)
            ->where('wtk.transaction_type', $legacyType)
            ->selectRaw("wtk.transaction_date AS settlement_time, wtk.transaction_id, 'Cash Withdrawal' AS brand,
                wtk.reference_id AS customer_number,
                wtk.amount, wtk.fee_amount, wtk.vat_amount, wtk.total_amount,
                ktl.location, ktl.name AS terminal, wtk.trans_ref_id AS transaction_reference,
                wtk.gh_balance AS new_balance, wtk.gh_type AS gaming_house")
            ->first();

        if (! $row) {
            return null;
        }

        $data = (array) $row;
        $newBalance = (float) $data['new_balance'];
        $oldBalance = $transactionType === 'GH_DEPOSIT'
            ? round($newBalance - (float) $data['amount'], 2)
            : round($newBalance + (float) $data['total_amount'], 2);

        return [$data, [
            'brand' => $data['brand'],
            'terminal' => $data['terminal'],
            'lines' => $this->lines([
                [$data['settlement_time'], 'Settlement Time'],
                [$data['terminal'], 'Terminal ID'],
                [$data['customer_number'], 'Account Number'],
                ['', 'Account Name'],
                [$data['fee_amount'], 'Fee'],
                [$data['vat_amount'], 'VAT'],
                [$data['total_amount'], 'Total Deposited'],
                [$data['amount'], 'Deposit Value'],
                [$oldBalance, 'Old Balance'],
                [$newBalance, 'New Balance'],
                [$data['gaming_house'], 'Gaming House'],
                [$brand, 'Service'],
                [$data['transaction_reference'], 'Ref. Number'],
            ]),
        ]];
    }

    // ── CARD_WITHDRAWAL ──────────────────────────────────────────────────────

    private function cardWithdrawal(string $transactionId): ?array
    {
        $row = $this->db()->table('webpos_transaction_kiosk as wtk')
            ->join('kiosk_card_transaction_info as kct', 'kct.invoice_number', '=', 'wtk.trans_ref_id')
            ->join('kiosk_terminal as ktl', 'ktl.id', '=', 'wtk.terminal_id')
            ->where('wtk.transaction_id', $transactionId)
            ->where('wtk.status', 0)
            ->where('wtk.transaction_type', 'CARD_WITHDRAWAL')
            ->selectRaw("wtk.transaction_date AS settlement_time, wtk.transaction_id, 'Cash Withdrawal' AS brand,
                wtk.reference_id AS customer_number,
                wtk.amount, wtk.fee_amount, wtk.vat_amount, wtk.total_amount,
                ktl.location, ktl.name AS terminal, wtk.trans_ref_id AS transaction_reference, kct.other_info AS card_info")
            ->first();

        if (! $row) {
            return null;
        }

        $data = (array) $row;
        $cardInfo = json_decode((string) $data['card_info'], true);
        if (empty($cardInfo)) {
            return null;
        }

        return [$data, [
            'brand' => $data['brand'],
            'terminal' => $data['terminal'],
            'lines' => $this->lines([
                [$data['settlement_time'], 'Settlement Time'],
                [$data['terminal'], 'Terminal ID'],
                [$data['amount'], 'Requested Amount'],
                [$data['amount'], 'Cash Dispensed'],
                [$data['fee_amount'], 'Fee'],
                [$data['vat_amount'], 'VAT'],
                [$cardInfo['NameOnCard'] ?? null, 'Name'],
                [$cardInfo['MaskedCardNumber'] ?? null, 'Card Number'],
                [$cardInfo['IssuerName'] ?? null, 'Issuer'],
                [$cardInfo['ApprovalCode'] ?? null, 'Approval Code'],
                [$cardInfo['ApprovedAmount'] ?? null, 'Approved Amount'],
                [$data['brand'], 'Service'],
            ]),
        ]];
    }

    // ── WU_SEND ──────────────────────────────────────────────────────────────

    private function westernUnionSend(string $transactionId): ?array
    {
        $row = $this->db()->table('webpos_transaction_kiosk as wtk')
            ->join('kiosk_terminal as ktl', 'ktl.id', '=', 'wtk.terminal_id')
            ->join('wu_transaction as wu', function ($join) {
                $join->on('wu.ref_id', '=', 'wtk.trans_ref_id')->where('wu.source', 'kiosk');
            })
            ->leftJoin('wu_validate as wuv', 'wuv.externalReferenceNo', '=', 'wu.ref_id')
            ->leftJoin('customers as sc', 'sc.id', '=', 'wu.customer_id')
            ->leftJoin('id_type as it', 'it.code', '=', 'sc.id_card_type')
            ->leftJoin('wu_bene as bene', 'bene.id', '=', 'wu.bene_id')
            ->leftJoin('customers as rc', 'rc.id', '=', 'bene.customer_id')
            ->leftJoin('state as str', 'str.id', '=', 'rc.state1')
            ->leftJoin('cities as ctr', 'ctr.id', '=', 'rc.city')
            ->where('wtk.transaction_type', 'MONEYTRANSFER_WU')
            ->where(function ($query) use ($transactionId) {
                $query->where('wtk.transaction_id', $transactionId)->orWhere('wtk.trans_ref_id', $transactionId);
            })
            ->where('wtk.status', 0)
            ->where('wu.type', 'SEND')
            ->where('wu.portal_status', 'COMPLETED')
            ->select('wu.*')
            ->selectRaw("wtk.transaction_date AS settlement_time, wtk.transaction_id, 'SEND WESTERN UNION' AS brand,
                wtk.amount, wtk.fee_amount, wtk.vat_amount, wtk.total_amount,
                ktl.location AS agent_name, ktl.name AS terminal,
                sc.id_card_num AS sender_card_num, it.description AS sender_id_type,
                IFNULL(JSON_EXTRACT(wuv.fields, '$.exchangeRate'), '0.00') AS exchange_rate,
                IFNULL(sc.address1, sc.address2) AS sender_address,
                sc.mobile AS sender_mobile, rc.mobile AS receiver_mobile")
            ->first();

        if (! $row) {
            return null;
        }

        $data = (array) $row;
        $mtcn = (string) $data['mtcn_number'];
        $mtcnFormatted = substr($mtcn, 0, 3).'-'.substr($mtcn, 3, 3).'-'.substr($mtcn, 6);
        $exchangeRate = str_replace('"', '', (string) $data['exchange_rate']);
        $totalTax = (float) $data['county_tax'] + (float) $data['state_tax'] + (float) $data['municipal_tax'];

        return [$data, [
            'brand' => $data['brand'],
            'terminal' => $data['terminal'],
            'logo' => 'wu',
            'lines' => $this->lines([
                [strtoupper((string) $data['sender_firstname']).' '.strtoupper((string) $data['sender_lastname']), 'SENDER: '],
                [strtoupper((string) $data['sender_address']), 'Address'],
                [$data['sender_mobile'], 'Telephone'],
                [strtoupper((string) $data['sender_id_type']), 'ID Type'],
                [$data['sender_card_num'], 'ID No:'],
                [strtoupper((string) $data['receiver_firstname']).' '.strtoupper((string) $data['receiver_lastname']), 'RECEIVER: '],
                ['YES _ NO', 'Will Receiver have valid ID?: '],
                [$data['secret_question'], 'Test Question: '],
                [$data['answer'], 'Answer: '],
                ['Send Western Union', 'Optional Services: '],
                [$data['transaction_date'] ?? $data['settlement_time'], 'Date & Time'],
                [strtoupper((string) $data['agent_name']), 'Agent Name'],
                [$data['terminal'], 'Terminal ID'],
                [str_replace('"', '', (string) $data['destination_currency_code']), 'Destination'],
                [$mtcnFormatted, 'MTCN: '],
                [$data['origination_currency_code'], 'Collection Currency: '],
                [$data['sending_fee'], 'Send Charge'],
                ['-', 'Promotion code: '],
                [(float) $data['vat'] + (float) $data['municipal_tax'], 'VAT - TIN #'],
                [$data['county_tax'], 'Foreign Exchange Fee: '],
                [$data['state_tax'], 'Government Stamp Tax: '],
                [$data['transaction_fee'], 'Convenience Fee: '],
                [$data['total_amount'], 'TOTAL:'],
                [$data['receivers_amount'], 'Payout Amount:'],
                [$totalTax, 'Total Tax:'],
                [$exchangeRate, 'Exchange Rate:'],
            ]),
        ]];
    }

    // ── WU_RECEIVE ───────────────────────────────────────────────────────────

    private function westernUnionReceive(string $transactionId): ?array
    {
        $row = $this->db()->table('webpos_transaction_kiosk as wtk')
            ->join('kiosk_terminal as ktl', 'ktl.id', '=', 'wtk.terminal_id')
            ->join('wu_transaction as wu', function ($join) {
                $join->on('wu.mtcn_number', '=', 'wtk.trans_ref_id')->where('wu.source', 'kiosk');
            })
            ->leftJoin('wu_validate as wuv', 'wuv.externalReferenceNo', '=', 'wu.ref_id')
            ->leftJoin('customers as rc', 'rc.id', '=', 'wu.customer_id')
            ->leftJoin('id_type as it', 'it.code', '=', 'rc.id_card_type')
            ->leftJoin('state as str', 'str.id', '=', 'wu.state_id')
            ->leftJoin('cities as ctr', 'ctr.id', '=', 'rc.city')
            ->where('wtk.transaction_type', 'MONEYTRANSFER_WU')
            ->where(function ($query) use ($transactionId) {
                $query->where('wtk.transaction_id', $transactionId)->orWhere('wtk.trans_ref_id', $transactionId);
            })
            ->where('wtk.status', 0)
            ->where('wu.type', 'RECEIVE')
            ->where('wu.portal_status', 'COMPLETED')
            ->select('wu.*')
            ->selectRaw("wtk.transaction_date AS settlement_time, wtk.transaction_id, 'RECEIVE WESTERN UNION' AS brand,
                wtk.amount, wtk.fee_amount, wtk.vat_amount, wtk.total_amount,
                ktl.location AS agent_name, ktl.name AS terminal,
                rc.id_card_type, rc.id_card_num, it.description AS id_type,
                wu.address AS receiver_address, rc.mobile AS receiver_mobile,
                IFNULL(JSON_EXTRACT(wuv.fields, '$.exchangeRate'), '0.00') AS exchange_rate")
            ->first();

        if (! $row) {
            return null;
        }

        $data = (array) $row;
        $mtcn = (string) $data['mtcn_number'];
        $mtcnFormatted = substr($mtcn, 0, 3).'-'.substr($mtcn, 3, 3).'-'.substr($mtcn, 6);
        $exchangeRate = str_replace('"', '', (string) $data['exchange_rate']);
        $totalTax = (float) $data['county_tax'] + (float) $data['state_tax'] + (float) $data['municipal_tax'];

        return [$data, [
            'brand' => $data['brand'],
            'terminal' => $data['terminal'],
            'logo' => 'wu',
            'lines' => $this->lines([
                [strtoupper((string) $data['receiver_firstname']).' '.strtoupper((string) $data['receiver_lastname']), 'RECEIVER: '],
                [strtoupper((string) $data['receiver_address']), 'Address'],
                [strtoupper((string) $data['id_type']), 'ID Type'],
                [$data['id_card_num'], 'ID No:'],
                [strtoupper((string) $data['sender_firstname']).' '.strtoupper((string) $data['sender_lastname']), 'SENDER: '],
                [$data['transaction_date'] ?? $data['settlement_time'], 'Date & Time'],
                [strtoupper((string) $data['agent_name']), 'Agent Name'],
                [$data['terminal'], 'Terminal ID'],
                [$data['sender_country'], 'Originating Country'],
                [$mtcnFormatted, 'MTCN: '],
                [str_replace('"', '', (string) $data['destination_currency_code']), 'Payout Currency: '],
                [$data['receivers_amount'], 'Amount Received'],
                [$data['amount'], 'Amount Sent: '],
                [$data['origination_currency_code'], 'Currency Sent: '],
                [$data['state_tax'], 'Government Stamp Tax: '],
                [$exchangeRate, 'Exchange Rate:'],
                [$totalTax, 'Tax:'],
                [$data['total_amount'], 'TOTAL:'],
            ]),
        ]];
    }
}
