<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A customer's tokenized credit/debit card linked to a merchant's checkout
 * (`customer_creditcard`) — backs Business Management's "credit/debit card"
 * review screen. Card status is derived from is_verified/is_rejected/
 * is_unlinked/status, mirroring legacy's getCustomerLinkedCard().
 */
#[Fillable([
    'is_verified', 'is_pending', 'is_manual_approved', 'status', 'is_rejected', 'rejected_reason', 'is_unlinked', 'updated_by',
])]
class CustomerCreditCard extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'customer_creditcard';

    public $timestamps = false;
}
