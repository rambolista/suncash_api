<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Global card-detail blacklist (`card_blacklist`) — written whenever a card is
 * blacklisted via Card Verification, matching legacy's blacklist_customer_creditcard().
 * Separate from `customer_creditcard.is_blacklisted`, which flags that one
 * specific card link; this table records the card's identifying details so
 * future link attempts using the same card can be screened against it.
 */
#[Fillable([
    'name', 'last_4_digit_number', 'card_type', 'expiry_date', 'is_active', 'validation_type', 'updated_by',
])]
class CardBlacklist extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'card_blacklist';

    const CREATED_AT = 'date_created';

    const UPDATED_AT = 'date_updated';
}
