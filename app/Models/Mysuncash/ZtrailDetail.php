<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;

/** Per-denomination cash-count line of a Kiosk "Zout" settlement (`ztrail_details`), joined to `ztrail` via `settlement_no`. Read-only. */
class ZtrailDetail extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'ztrail_details';

    public $timestamps = false;

    protected $guarded = ['*'];
}
