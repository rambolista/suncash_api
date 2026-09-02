<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;

/** Meter clear/replenish events (`kiosk_meters_user`) — a secondary Kiosk Statement ledger source (Clear Acceptor/Dispenser, Add Bin). Read-only. */
class KioskMeterUser extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'kiosk_meters_user';

    public $timestamps = false;

    protected $guarded = ['*'];
}
