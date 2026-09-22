<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Checkout/payment credit card whitelist requests (`card_whitelist`) — legacy `tools/creditcard_approval`. */
#[Fillable(['status', 'remarks'])]
class CardWhitelist extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'card_whitelist';
}
