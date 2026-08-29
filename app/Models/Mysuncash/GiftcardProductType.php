<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A denomination/variant of a gift-card product (`giftcard_producttypes`) —
 * every product gets one default row at creation, with more added later for
 * other card designs/amount ranges. Joined to its parent via `product_id`
 * (merchant_giftcards.id), not the vendor-mirrored `gc_product_id`.
 */
class GiftcardProductType extends Model
{
    public const STATUS_PENDING = 'PENDING';

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_DISABLED = 'DISABLED';

    protected $connection = 'mysuncash';

    protected $table = 'giftcard_producttypes';

    public $timestamps = false;

    protected $guarded = ['*'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(MerchantGiftcard::class, 'product_id');
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class, 'client_id');
    }
}
