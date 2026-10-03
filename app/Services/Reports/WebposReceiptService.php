<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Receipt reprint behind the Receipt button on "Reports > Transactions"
 * detail rows (legacy `reports_::reprintReceipt` -> `print_load_receipt` /
 * `print_load_receiptv2`, both TCPDF).
 *
 * Legacy rebuilt the receipt one of two ways: from the receipt URL the
 * WebPOS saved at sale time (`reprint_webpos_transaction.receipt_url`) for
 * LOAD / BANK_DEPOSIT / GOVERNMENT_PAYMENT ("VAT Sales Receipt" layout), or
 * from the `webpos_transaction` row itself for everything else. Both paths
 * are kept; legacy's movie/airline-ticket variants are unreachable from this
 * screen (it passes the type KEY, never "MOVIE TICKET"/"AIRLINE TICKET") so
 * they aren't ported.
 */
class WebposReceiptService
{
    private const SAVED_URL_TYPES = ['LOAD', 'BANK_DEPOSIT', 'GOVERNMENT_PAYMENT'];

    private const CUSTOMER_ONLY_TYPES = ['LOAD', 'CASHOUT_VIA_MOBILE', 'CASHOUT_CODE', 'DONATE', 'TOP UP'];

    /**
     * @throws ValidationException
     */
    public function build(string $transactionId, string $type, int $merchantRecordId): array
    {
        $db = DB::connection('mysuncash');
        $dbType = $type === 'BILLPAY_BUSINESS' ? 'BILLPAY' : $type;

        $row = $db->table('webpos_transaction')
            ->where('transaction_id', $transactionId)->where('transaction_type', $dbType)
            ->first(['branch_id', 'transaction_date', 'amount', 'fee_amount', 'vat_amount', 'stamp_amount', 'exclude_fee', 'terminal_user_id']);
        if (! $row) {
            throw ValidationException::withMessages(['id' => ['Transaction not found.']]);
        }

        $branch = $db->table('branch')->where('id', $row->branch_id)->first(['island', 'address1', 'address2', 'city']);
        $island = $branch ? $db->table('island')->where('status', 'A')->where('id', $branch->island)->value('name') : null;
        $location = $branch ? $db->table('branch as b')
            ->join('clients as c', 'c.id', '=', 'b.client_record_id')
            ->join('island_city as ic', 'b.island_location', '=', 'ic.city_id')
            ->where('b.status', 'A')->where('b.island', $branch->island)->where('c.client_status_id', '<>', 2)->where('b.id', $row->branch_id)
            ->first(['b.description', 'ic.city_name']) : null;

        $cashier = $db->table('merchant_terminal_users')->where('id', $row->terminal_user_id)->first(['first_name', 'last_name']);

        $data = [
            'merchant_id' => $merchantRecordId,
            'location' => $location ? $location->description.' - '.$location->city_name : '',
            'address' => $branch ? trim(implode(' ', array_filter([$branch->address1, $branch->address2, $branch->city]))) : '',
            'island' => $island ?: '',
            'cashier' => $cashier ? $cashier->first_name.' '.$cashier->last_name : '',
            'transaction_id' => $transactionId,
            'date' => date('m/d/Y | h:i A', strtotime($row->transaction_date)),
            'vat_layout' => false,
            'type_text' => $type,
            'amount' => (float) $row->amount,
            'fee' => (float) $row->fee_amount,
            'vat' => (float) $row->vat_amount,
            'stamp' => (float) $row->vat_amount, // legacy passes vat_amount as the stamp too
            'customer_name' => '', 'customer_mobile' => '', 'receiver_name' => '', 'receiver_mobile' => '',
            'biller_account' => '', 'biller_name' => '', 'extra' => null,
        ];

        $cashout = $db->table('cashout_transaction_detailsv3')->where('cashout_id', $transactionId)
            ->first(['sender_fname', 'sender_lname', 'sender_mobile', 'bene_fname', 'bene_lname', 'bene_mobile']);
        if ($cashout) {
            $data['customer_name'] = $cashout->sender_fname.' '.$cashout->sender_lname;
            $data['customer_mobile'] = $cashout->sender_mobile;
            $data['receiver_name'] = $cashout->bene_fname.' '.$cashout->bene_lname;
            $data['receiver_mobile'] = $cashout->bene_mobile;
        }

        $saved = $db->table('webpos_transaction as a')
            ->join('reprint_webpos_transaction as r', 'a.transaction_id', '=', 'r.transaction_id')
            ->where('a.transaction_id', $transactionId)->where('a.transaction_type', $dbType)->where('r.transaction_type', $dbType)
            ->value('r.receipt_url');

        // Legacy: `$newreprint && BANK_DEPOSIT || LOAD` — LOAD takes the saved-URL layout even with no saved row.
        if (($saved && $type === 'BANK_DEPOSIT') || $type === 'LOAD' || $type === 'GOVERNMENT_PAYMENT') {
            return $this->savedLayout($data, $type, (string) $saved);
        }

        return $this->rowLayout($data, $type, (int) $row->exclude_fee);
    }

    /** `print_load_receipt` — figures straight from the webpos row. */
    private function rowLayout(array $d, string $type, int $excludeFee): array
    {
        $d['total'] = $d['amount'] + $d['fee'];
        if ($type === 'BILLPAY') {
            $d['total'] = $d['amount'] + $d['fee'] + $d['vat'] + $d['stamp'];
            $d['show_vat'] = true;
        } elseif ($type === 'BANK_DEPOSIT') {
            if ($excludeFee !== 0) {
                $d['total'] = $d['amount'] + $d['fee'];
            } else {
                $d['total'] = $d['amount'];
                $d['amount'] -= $d['fee'];
            }
        }

        $d['show_vat'] ??= false;
        $d['show_stamp'] = false;
        $d['section'] = match (true) {
            in_array($type, self::CUSTOMER_ONLY_TYPES, true) => 'customer',
            $type === 'BANK_DEPOSIT' => 'bank',
            $type === 'BILLPAY' => 'none',
            default => 'sender_receiver',
        };

        return $this->finish($d, false);
    }

    /** `print_load_receiptv2` — the receipt URL saved at sale time (`/cashier/merchant/txn/date/amount/name/mobile/type/...`). */
    private function savedLayout(array $d, string $type, string $url): array
    {
        $p = array_pad(explode('/', $url), 19, '');
        $dec = fn ($v) => urldecode((string) $v);
        $num = fn ($v) => (float) str_replace(',', '', $dec($v));

        $d['vat_layout'] = true;
        if ($url !== '') {
            $d['cashier'] = $dec($p[1]);
            $d['merchant_id'] = $p[2];
            $d['transaction_id'] = $p[3];
            $d['date'] = date('m/d/Y | h:i A', strtotime($dec($p[4])));
            $d['amount'] = $num($p[5]);
            $d['customer_name'] = $dec($p[6]) === 'NULL' ? '' : $dec($p[6]);
            $d['customer_mobile'] = $p[7] === 'NULL' ? '' : $p[7];
            $d['type_text'] = $dec($p[8]);
            $d['receiver_name'] = $dec($p[9]) === 'NULL' ? '' : $dec($p[9]);
            $d['receiver_mobile'] = $p[10] === 'NULL' ? '' : $p[10];
            $d['fee'] = $num($p[11]);
            $d['biller_account'] = $p[14] === 'null' ? '' : $dec($p[14]);
            $d['biller_name'] = $p[15] === 'null' ? '' : $dec($p[15]);
            if ($type === 'GOVERNMENT_PAYMENT') {
                $d['extra'] = ['branch_name' => $dec($p[16]), 'reference_code' => $dec($p[17]), 'invoice_number' => $dec($p[18])];
            }
        }

        // Receipts are rounded to the nearest 5 cents.
        $penny = fn (float $v) => round(round($v / 0.05) * 0.05, 2);
        if ($type === 'BANK_DEPOSIT' && $d['biller_name'] === '') {
            $d['total'] = $penny($d['amount']);
            $d['amount'] -= $d['fee'];
        } else {
            $d['total'] = $penny($d['amount'] + $d['fee']);
        }

        $d['show_vat'] = false;
        $d['show_stamp'] = false;
        $d['section'] = match ($type) {
            'LOAD' => 'customer',
            'BANK_DEPOSIT' => 'bank',
            'GOVERNMENT_PAYMENT' => 'government',
            default => 'sender_receiver',
        };

        return $this->finish($d, true);
    }

    private function finish(array $d, bool $vatLayout): array
    {
        $d['vat_layout'] = $vatLayout;
        $d['anonymous'] = $d['customer_name'] === 'NONE' && $d['customer_mobile'] === 'NONE';
        $d['customer_mobile_fmt'] = $this->phone($d['customer_mobile']);
        $d['receiver_mobile_fmt'] = $this->phone($d['receiver_mobile']);
        $d['customer_label'] = $d['type_text'] === 'DONATE' ? 'DONOR' : 'CUSTOMER';

        return $d;
    }

    private function phone(string $mobile): string
    {
        return preg_replace('/^(\d{1})(\d{3})(\d{3})(\d{4})$/', '$1 ($2) $3-$4', $mobile) ?? $mobile;
    }
}
