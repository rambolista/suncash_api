<?php

namespace App\Services;

/**
 * Reproduces the AES-128-CBC + HMAC scheme used across mysuncash-stage
 * (accounts_model.php) for user_account.password, so records created here
 * remain login-compatible with the legacy Merchant portal.
 */
class LegacyCredentialCipher
{
    private const KEY_PREFIX = 'c8013662fafe006738e2bfdd557bb07b';

    private const CIPHER = 'AES-128-CBC';

    /**
     * @return array{encrypted: string, user_key: string}
     */
    public static function encrypt(string $plaintext): array
    {
        $userKey = bin2hex(random_bytes(16));
        $key = self::KEY_PREFIX . $userKey;

        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $iv = random_bytes($ivLength);

        $ciphertextRaw = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);
        $hmac = hash_hmac('sha256', $ciphertextRaw, $key, true);

        return [
            'encrypted' => base64_encode($iv . $hmac . $ciphertextRaw),
            'user_key' => $userKey,
        ];
    }

    public static function generateMerchantKey(): string
    {
        return md5(uniqid((string) time(), true)) . md5(sha1((string) microtime(true)));
    }
}
