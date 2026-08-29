<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A merchant's gift-card product (`merchant_giftcards`) — used read-only as
 * selectable options on Business Management's "Voucher Setting" button
 * (alongside the two built-in SunCash/Unibucks voucher types), and as the
 * subject of the Giftcards > Products admin screen's activate/deactivate
 * actions (status only; nothing else on this table is admin-editable).
 */
class MerchantGiftcard extends Model
{
    public const STATUS_PENDING = 'PENDING';

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_DISABLED = 'DISABLED';

    protected $connection = 'mysuncash';

    protected $table = 'merchant_giftcards';

    public $timestamps = false;

    protected $guarded = ['*'];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class, 'client_id');
    }

    public function productTypes(): HasMany
    {
        return $this->hasMany(GiftcardProductType::class, 'product_id');
    }
}
