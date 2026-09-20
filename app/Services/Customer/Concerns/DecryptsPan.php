<?php

namespace App\Services\Customer\Concerns;

use Illuminate\Support\Facades\DB;

/**
 * Legacy's `HideIt` library + `tools::get_pan_key()` — the AES-256-CBC
 * scheme `customer_banks`/`kiosk_bank_accounts` use to encrypt
 * `account_name`/`account_number` at rest, keyed by a DB-stored,
 * KEK-wrapped DEK. Shared by every feature that needs to show an admin the
 * real account number for a manual bank transfer (Customer Settlements,
 * Customer Management's linked bank accounts).
 */
trait DecryptsPan
{
    private const PAN_KEK = 'cfbe176207b80774e8911c10893f5a0f';

    private ?string $panKeyCache = null;

    private function hideItDecrypt(string $pass, string $encrypted, string $iv): ?string
    {
        $result = openssl_decrypt($encrypted, 'aes-256-cbc', $pass, false, substr(sha1($iv), 3, 16));

        return $result === false ? null : $result;
    }

    private function panKey(): string
    {
        if ($this->panKeyCache !== null) {
            return $this->panKeyCache;
        }

        $dekEnc = DB::connection('mysuncash')->table('keys')->orderByDesc('timestamp')->value('key');
        $this->panKeyCache = $dekEnc ? (string) $this->hideItDecrypt(self::PAN_KEK, $dekEnc, sha1('aes-256-cbc')) : '';

        return $this->panKeyCache;
    }

    private function decryptPan(?string $encrypted, string $ivSeed): ?string
    {
        if (! filled($encrypted)) {
            return null;
        }

        return $this->hideItDecrypt($this->panKey(), $encrypted, sha1(md5($ivSeed))) ?: null;
    }
}
