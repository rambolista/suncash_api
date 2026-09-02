<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;

/** Per-day cash-meter movement (`kiosk_cash_meters_trx`, `type` in/out) — used to compute the Kiosk Statement ledger's opening balance. Read-only. */
class KioskCashMeterTrx extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'kiosk_cash_meters_trx';

    public $timestamps = false;

    protected $guarded = ['*'];
}
