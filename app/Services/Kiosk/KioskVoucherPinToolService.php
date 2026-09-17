<?php

namespace App\Services\Kiosk;

use App\Models\Mysuncash\MerchantVoucher;
use App\Models\Mysuncash\UniversalVoucher;
use App\Models\Mysuncash\WebLog;
use Illuminate\Validation\ValidationException;

/**
 * "Kiosk > Voucher Pin Tool" (legacy `fastpay::voucher_tool()` /
 * `check_voucher_info()` / `tools_model::get_credit_voucher_info()`). A
 * read-only support tool: given a voucher code + type, reveals its PIN,
 * status, and voucher/redeemed date.
 *
 * Every lookup — found or not — is logged to `web_logs` with
 * `log_type='GET_VOUCHER_INFO'`, exactly like legacy. Those rows are what
 * "Kiosk > Reports > Voucher Access" already reads
 * (`KioskVoucherAccessReportService`), so this tool is that report's
 * missing write side.
 *
 * PINs are stored either as plaintext digits or AES-128-CBC encrypted
 * (base64). Legacy decided which with `is_numeric($pin)`; this uses the
 * table's own `is_pin_encrypted` flag instead — a column added after that
 * heuristic was written — since it's the authoritative signal and produces
 * identical results on real data.
 *
 * Legacy also had a permission check for this feature
 * (`kiosk_model::is_admin_user_allowed()`), but it was commented out and
 * hardcoded to always allow — effectively open to any logged-in admin.
 * This replaces that with the standard MODULE_PATH/can_view gate instead
 * of leaving it wide open.
 */
class KioskVoucherPinToolService
{
    private const TYPE_SUNCASH = 'suncash';

    private const TYPE_UNIBUCKS = 'unibucks';

    /**
     * @throws ValidationException
     */
    public function lookup(string $voucherCode, string $voucherType, string $actorId, string $actorIp): array
    {
        $voucher = $voucherType === self::TYPE_SUNCASH
            ? MerchantVoucher::where('voucher_code', $voucherCode)->first()
            : UniversalVoucher::where('voucher_code', $voucherCode)
                ->where('voucher_product_id', $voucherType === self::TYPE_UNIBUCKS ? 2 : 3)
                ->first();

        WebLog::create([
            'user_id' => $actorId,
            'updated_by' => $actorId,
            'log_type' => 'GET_VOUCHER_INFO',
            'data' => $voucherCode,
            'user_ip_address' => $actorIp,
            'cloudflare_ip_address' => $actorIp,
            'web_channel' => 'admin',
        ]);

        if (! $voucher) {
            throw ValidationException::withMessages(['code' => ['No record found.']]);
        }

        return [
            'pin' => $this->pin($voucher),
            'voucher_date' => $voucher->voucher_date,
            'status' => $voucher->status,
            'redeemed_date' => $voucher->status === $voucher::STATUS_ACTIVE ? null : $voucher->update_date,
        ];
    }

    private function pin(MerchantVoucher|UniversalVoucher $voucher): ?string
    {
        if (! $voucher->is_pin_encrypted) {
            return $voucher->pin;
        }

        $key = (string) config('services.voucher.crypt_key');
        $iv = base64_decode((string) config('services.voucher.iv'));
        $decrypted = openssl_decrypt(base64_decode((string) $voucher->pin), 'aes-128-cbc', $key, OPENSSL_RAW_DATA, $iv);

        return $decrypted !== false && $decrypted !== '' ? $decrypted : null;
    }
}
