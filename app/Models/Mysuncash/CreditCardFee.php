<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Flat credit-card processing/transaction fees (`credit_card_fees`) — legacy `tools/creditcard_fees`. Fixed, seeded rows (Processing/Transaction/Customer_Processing/Customer_Transaction); only the amount is ever edited. */
#[Fillable(['value', 'modification_date', 'user_id_modify'])]
class CreditCardFee extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'credit_card_fees';

    public $timestamps = false;
}
