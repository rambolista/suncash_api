<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;

/** A cash-pickup/cashout-code header row (`cashout_transactionsv3`). Status: 0=active, 1=redeemed, 3=voided. */
class CashoutTransactionV3 extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'cashout_transactionsv3';

    public $timestamps = false;

    protected $guarded = ['*'];
}
