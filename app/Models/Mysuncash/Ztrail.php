<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;

/** Settlement audit trail (`ztrail`) — used only to recover a `settlement_no` for meter clear/replenish ledger rows via a ±20s timestamp match. Read-only. */
class Ztrail extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'ztrail';

    public $timestamps = false;

    protected $guarded = ['*'];
}
