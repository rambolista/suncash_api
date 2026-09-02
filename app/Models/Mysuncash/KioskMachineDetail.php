<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A kiosk's live hardware/connectivity telemetry (`kiosk_machine_details`),
 * one row per `kiosk_terminal` (`kiosk_id`). `status` ('OK'/'OFFLINE') is
 * kept fresh by the physical terminal's own heartbeat pipeline (a separate
 * `services` API this admin panel doesn't own) — see
 * `KioskMonitoringService` for why this rewrite reads it directly instead
 * of replicating legacy's extra vendor ("Engage") sync step.
 */
#[Fillable(['status', 'offline_date', 'paper', 'acceptor', 'dispenser', 'acceptor_cash', 'dispenser_cash', 'is_acknowledge', 'acknowledge_date', 'updated_by', 'update_date'])]
class KioskMachineDetail extends Model
{
    public const STATUS_OK = 'OK';

    public const STATUS_OFFLINE = 'OFFLINE';

    protected $connection = 'mysuncash';

    protected $table = 'kiosk_machine_details';

    public $timestamps = false;

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(KioskTerminal::class, 'kiosk_id');
    }
}
