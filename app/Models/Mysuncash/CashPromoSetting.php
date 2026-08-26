<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Cash-prize inventory for a promo (Weekly/Instant draws), with island-targeting rules. */
#[Fillable([
    'price', 'quantity', 'remaining_quantity', 'description', 'promo_type', 'created_date', 'updated_date',
    'is_instant_reusable', 'status', 'target_group_type', 'target_group', 'draw_type', 'draw_date',
    'is_process', 'other_service',
])]
class CashPromoSetting extends Model
{
    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_DELETED = 'DELETED';

    public const STATUS_USED = 'USED';

    protected $connection = 'mysuncash';

    protected $table = 'cash_promo_settings';

    public $timestamps = false;
}
