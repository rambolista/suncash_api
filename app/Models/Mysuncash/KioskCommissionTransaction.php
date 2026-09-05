<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A terminal's automated commission payout awaiting review (`kiosk_commission_transactions`), created by an out-of-band process not present in this codebase. */
#[Fillable([
    'status', 'updated_by', 'approved_by', 'approved_date', 'approved_note',
    'processed_commission_rate', 'processed_commission_type', 'processed_commission_payment',
    'rejected_by', 'rejected_date', 'rejected_note',
    'rejected_commission_rate', 'rejected_commission_type', 'rejected_commission_payment',
])]
class KioskCommissionTransaction extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_REJECTED = 'rejected';

    protected $connection = 'mysuncash';

    protected $table = 'kiosk_commission_transactions';

    public $timestamps = false;

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(KioskTerminal::class, 'terminal_id');
    }
}
