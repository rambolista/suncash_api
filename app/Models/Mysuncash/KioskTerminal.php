<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** A registered kiosk/ATM device (`kiosk_terminal`). `status = 'A'` means active/in-service. */
class KioskTerminal extends Model
{
    public const STATUS_ACTIVE = 'A';

    protected $connection = 'mysuncash';

    protected $table = 'kiosk_terminal';

    public $timestamps = false;

    protected $guarded = ['*'];

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
}
