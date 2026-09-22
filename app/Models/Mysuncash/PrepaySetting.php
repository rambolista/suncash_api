<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Prepaynation topup settings (`prepay_settings`) — legacy `tools/prepay_settings`. Rows keyed by `setting_descr` (replenish_balance/notification_address), single `suncash` channel. */
#[Fillable(['setting_value', 'updated_date', 'updated_by'])]
class PrepaySetting extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'prepay_settings';

    public $timestamps = false;
}
