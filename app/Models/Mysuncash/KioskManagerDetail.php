<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;

/** A kiosk manager's own payout bank details (`kiosk_manager_details`), unencrypted (unlike `customer_banks`/`kiosk_bank_accounts`). */
class KioskManagerDetail extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'kiosk_manager_details';

    public $timestamps = false;

    protected $guarded = ['*'];
}
