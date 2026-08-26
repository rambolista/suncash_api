<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Physical-prize inventory for a promo (Weekly/Grand/Instant draws, etc). */
#[Fillable([
    'branch_id', 'merchant_id', 'image_url', 'item_description', 'quantity', 'remaining_quantity',
    'is_instant_reusable', 'created_date', 'updated_date', 'event_description', 'status',
    'draw_type', 'draw_date', 'is_process', 'other_service',
])]
class PromoItem extends Model
{
    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_INACTIVE = 'INACTIVE';

    public const STATUS_DELETED = 'DELETED';

    public const STATUS_USED = 'USED';

    protected $connection = 'mysuncash';

    protected $table = 'promo_items';

    public $timestamps = false;

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class, 'merchant_id');
    }
}
