<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer's tokenized credit/debit card linked to a merchant's checkout
 * (`customer_creditcard`) — backs Business Management's "credit/debit card"
 * review screen AND Customers' "Card Verification" queue (pending/approved/
 * rejected/blacklisted). Card status is derived from is_verified/is_rejected/
 * is_blacklisted/is_unlinked/status, mirroring legacy's getCustomerLinkedCard()
 * and tools_model::get_card_list().
 */
#[Fillable([
    'is_verified', 'is_pending', 'is_manual_approved', 'status', 'is_rejected', 'is_blacklisted', 'rejected_reason', 'is_unlinked', 'updated_by',
])]
class CustomerCreditCard extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'customer_creditcard';

    public $timestamps = false;

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(UserAccount::class, 'updated_by');
    }
}
