<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;

/** Recycler/cashbox/reserve maintenance events (`kiosk_maintenance_details`) — a secondary Kiosk Statement ledger source. Read-only. */
class KioskMaintenanceDetail extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'kiosk_maintenance_details';

    public $timestamps = false;

    protected $guarded = ['*'];
}
