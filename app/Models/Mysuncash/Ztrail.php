<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;

/**
 * Kiosk settlement/"Zout" audit trail (`ztrail`) — one row per settlement
 * event. Used by `KioskStatementService` to recover a `settlement_no` for
 * meter clear/replenish ledger rows via a ±20s timestamp match, and by
 * `KioskZoutReportService` (Kiosk > Zout Reports) as the settlement header
 * joined against `ztrail_details` (the per-denomination cash-count lines).
 * Read-only.
 */
class Ztrail extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'ztrail';

    public $timestamps = false;

    protected $guarded = ['*'];
}
