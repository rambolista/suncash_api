<?php

namespace App\Services\Concerns;

use App\Models\Mysuncash\MerchantVoucher;
use App\Models\Mysuncash\UniversalVoucher;

/**
 * `merchant_vouchers`/`universal_vouchers.pin` is stored either as plaintext
 * digits or AES-128-CBC encrypted (base64) — the table's own `is_pin_encrypted`
 * flag says which (legacy used `is_numeric($pin)` instead; this is the
 * authoritative signal and matches it on real data). Shared by Kiosk >
 * Voucher Pin Tool and Transactions > Resend Voucher.
 */
trait DecryptsVoucherPin
{
    private function voucherPin(MerchantVoucher|UniversalVoucher $voucher): ?string
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
