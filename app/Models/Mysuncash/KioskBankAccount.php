<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;

/**
 * A Kiosk-channel customer's linked bank account (`kiosk_bank_accounts`).
 * `customer_name`/`account_number` are encrypted at rest, keyed off
 * `customer_number` — see `CustomerSettlementService::decryptPan()`.
 */
class KioskBankAccount extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'kiosk_bank_accounts';

    public $timestamps = false;

    protected $guarded = ['*'];
}
