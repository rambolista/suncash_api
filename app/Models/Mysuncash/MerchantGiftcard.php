<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;

/**
 * Read-only: a merchant's active gift-card products (`merchant_giftcards`),
 * offered as selectable options on Business Management's "Voucher Setting"
 * button alongside the two built-in SunCash/Unibucks voucher types.
 */
class MerchantGiftcard extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'merchant_giftcards';

    public $timestamps = false;

    protected $guarded = ['*'];
}
