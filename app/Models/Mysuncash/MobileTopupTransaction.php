<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;

/** A mobile airtime top-up transaction (`mobile_topup_transactions`) — `provider` (aliv/emida/paynation) resolves a kiosk `KioskTopup` row's real sub-type. Read-only. */
class MobileTopupTransaction extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'mobile_topup_transactions';

    public $timestamps = false;

    protected $guarded = ['*'];
}
