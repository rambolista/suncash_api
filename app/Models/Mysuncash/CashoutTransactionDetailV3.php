<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;

/** Sender/beneficiary detail for a cash-pickup transaction (`cashout_transaction_detailsv3`). */
class CashoutTransactionDetailV3 extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'cashout_transaction_detailsv3';

    public $timestamps = false;

    protected $guarded = ['*'];
}
