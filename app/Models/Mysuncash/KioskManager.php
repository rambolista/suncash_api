<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** A kiosk's manager (`kiosk_managers`) — the "customer" on `KioskCommission`-channel settlement requests. */
class KioskManager extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'kiosk_managers';

    public $timestamps = false;

    protected $guarded = ['*'];

    public function details(): HasOne
    {
        return $this->hasOne(KioskManagerDetail::class, 'manager_id');
    }
}
