<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;

/**
 * Meter clear/replenish events (`kiosk_meters_user`) — a secondary source
 * folded into Kiosk Statement's ledger (`KioskStatementService::
 * meterClearOrAddRows()`), and the PRIMARY source for Kiosk > Replenish
 * Reports (`KioskReplenishReportService`), which lists/decodes these rows
 * directly (categories: REPLENISH, PRINT_CLEAR_ACCEPTOR_METERS,
 * PRINT_CLEAR_DISPENSER_METERS, and `_v2` variants). Read-only.
 */
class KioskMeterUser extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'kiosk_meters_user';

    public $timestamps = false;

    protected $guarded = ['*'];
}
