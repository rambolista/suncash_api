<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Aliv topup reseller settings (`aliv_settings`) — legacy `tools/aliv_settings`. Two fixed rows keyed by `code` (replenish_balance, notification_address). */
#[Fillable(['value'])]
class AlivSetting extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'aliv_settings';

    public $timestamps = false;
}
