<?php

namespace App\Services\Transactions;

use App\Models\ActivityLog;
use App\Models\Mysuncash\MerchantVoucher;
use App\Models\Mysuncash\UniversalVoucher;
use App\Models\User;
use App\Services\Concerns\DecryptsVoucherPin;
use App\Services\Notifications\Sms\SmsManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * "Transactions > Resend Voucher" (legacy `Tools::resend_voucher()` /
 * `resendVoucher()`, backed by `universal_voucher_model::
 * resendVoucherDetails()`). Unlike Void Transaction/Resend Receipt, this is
 * a flat single-field form — no transaction search step. Given a bare
 * voucher code, it re-sends that voucher's own original code/PIN/amount via
 * SMS and email to whichever contact details are stored on the voucher
 * record; nothing about the voucher is regenerated or mutated.
 *
 * Legacy tries `merchant_vouchers` (SunCash-branded) first, then falls back
 * to `universal_vouchers` (UniBucks/other-branded) — same order kept here.
 */
class ResendVoucherService
{
    use DecryptsVoucherPin;

    public function __construct(private readonly SmsManager $sms) {}

    /** @throws ValidationException */
    public function resend(string $voucherCode, User $actor): array
    {
        $voucher = MerchantVoucher::where('voucher_code', $voucherCode)->first()
            ?? UniversalVoucher::where('voucher_code', $voucherCode)->first();

        if (! $voucher) {
            throw ValidationException::withMessages(['voucher_number' => ['Voucher not found.']]);
        }
        if ($voucher->status === 'REDEEMED') {
            throw ValidationException::withMessages(['voucher_number' => ['Voucher already redeemed.']]);
        }
        if ($voucher->status !== $voucher::STATUS_ACTIVE) {
            throw ValidationException::withMessages(['voucher_number' => ['Voucher is unavailable.']]);
        }

        $mobile = $voucher instanceof MerchantVoucher ? $voucher->mobile : $voucher->receiver_mobile;
        $email = $voucher instanceof MerchantVoucher ? $voucher->email : $voucher->receiver_email;

        if (blank($mobile) && blank($email)) {
            throw ValidationException::withMessages(['voucher_number' => ['This voucher has no mobile number or email on file.']]);
        }

        $productName = $this->productName($voucher);
        $pin = $this->voucherPin($voucher);

        $message = "You received a {$productName}. Code: {$voucher->voucher_code} PIN: {$pin} Amount: BSD "
            .number_format((float) $voucher->amount, 2)
            .'. Secure Your Funds - DO NOT SHARE VOUCHER INFO!';

        // A disabled/simulated SMS gateway isn't a failure of this action — same
        // treatment as PushNotificationService: contact info existed, we just
        // didn't actually reach the gateway in this environment.
        $sentSms = filled($mobile) && ($this->sms->send($mobile, $message)['sent'] ?? false);

        $sentEmail = false;
        if (filled($email)) {
            Mail::raw($message, fn ($mail) => $mail->to($email)->subject("Your {$productName}"));
            $sentEmail = true;
        }

        $actorName = $actor->name ?? $actor->email;
        ActivityLog::recordAction($actor, 'Resend Voucher', 'sent', "Resent voucher {$voucher->voucher_code} (by {$actorName}).");

        return [
            'message' => $sentSms || $sentEmail
                ? 'Voucher successfully resent.'
                : 'SMS sending is disabled in this environment — no message was actually sent.',
        ];
    }

    private function productName(MerchantVoucher|UniversalVoucher $voucher): string
    {
        if ($voucher instanceof UniversalVoucher && $voucher->voucher_product_id > 0) {
            $description = DB::connection('mysuncash')->table('voucher_products')->where('id', $voucher->voucher_product_id)->value('description');
            if (filled($description)) {
                return $description;
            }
        }

        return 'Suncash Voucher';
    }
}
