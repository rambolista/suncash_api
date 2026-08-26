<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;

/** Master config row per promo campaign (one per SUNCASH_ACTIVE_PROMO code, e.g. summer_cool_down_reloaded_promo). */
class SuncashPromoSetting extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'suncash_promo_settings';

    public $timestamps = false;
}
