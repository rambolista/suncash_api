<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** BTC (Emida) topup settings (`emida_settings`) — legacy `tools/emida_settings`. Rows keyed by `setting_descr` + `channel` (replenish_amount/notification_address, per suncash/customer_app channel). */
#[Fillable(['setting_value', 'updated_date', 'updated_by'])]
class EmidaSetting extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'emida_settings';

    public $timestamps = false;
}
