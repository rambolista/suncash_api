<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Flat per-transaction-type customer fee (`customer_transaction_fee`) — legacy `tools/transaction_fee`. Fixed, seeded rows (SENDMONEY/BILLPAY/DONATE); only the amount is ever edited. */
#[Fillable(['transaction_fee', 'update_date'])]
class CustomerTransactionFee extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'customer_transaction_fee';

    public $timestamps = false;
}
