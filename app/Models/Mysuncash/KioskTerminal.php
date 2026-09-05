<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** A registered kiosk/ATM device (`kiosk_terminal`). `status = 'A'` means active/in-service. */
#[Fillable([
    'kiosk_branch_id', 'code', 'name', 'device_id', 'username', 'password',
    'location', 'island', 'terminal_type', 'acceptor_high_alert', 'dispenser_low_alert',
    'manager_id', 'profile_id', 'commission_type', 'commission_fixed_value',
    'status', 'access_modules', 'create_by', 'create_date', 'update_by', 'update_date',
])]
class KioskTerminal extends Model
{
    public const STATUS_ACTIVE = 'A';

    public const STATUS_DELETED = 'D';

    /** `commission_type`: 1=Fixed, 2=Percentage (via profile), 3=Greater Amount (fixed vs. profile), 4=Fixed + Percentage. */
    public const COMMISSION_TYPES = [
        1 => 'Fixed',
        2 => 'Percentage',
        3 => 'Greater Amount',
        4 => 'Fixed + Percentage',
    ];

    protected $connection = 'mysuncash';

    protected $table = 'kiosk_terminal';

    public $timestamps = false;

    public function branch(): BelongsTo
    {
        return $this->belongsTo(KioskBranch::class, 'kiosk_branch_id');
    }

    public function islandRecord(): BelongsTo
    {
        return $this->belongsTo(Island::class, 'island');
    }

    public function machineDetail(): HasOne
    {
        return $this->hasOne(KioskMachineDetail::class, 'kiosk_id');
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(KioskManager::class, 'manager_id');
    }
}
