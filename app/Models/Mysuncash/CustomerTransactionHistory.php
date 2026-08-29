<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A customer-facing statement row (`customer_transaction_histories`) —
 * legacy's clients_model::saveCustomerTransactionHistory(), written when a
 * Business Billpay B2C payment credits a customer's card.
 */
#[Fillable([
    'transaction_id', 'customer_id', 'ezkard_account_id', 'transaction_reference', 'transaction_type',
    'category', 'status', 'description', 'amount', 'transaction_fee', 'sending_fee', 'vat',
    'channel', 'running_balance', 'finance_orientation', 'created_date',
])]
class CustomerTransactionHistory extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'customer_transaction_histories';

    public $timestamps = false;
}
