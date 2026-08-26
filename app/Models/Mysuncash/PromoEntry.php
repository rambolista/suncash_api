<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One row = one earned raffle ticket, written by the customer-facing services app's ticket engine. */
class PromoEntry extends Model
{
    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_WON = 'WON';

    public const STATUS_CLAIMED = 'CLAIMED';

    public const STATUS_INELIGIBLE = 'INELIGIBLE';

    protected $connection = 'mysuncash';

    protected $table = 'promo_entries';

    public $timestamps = false;

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
}
