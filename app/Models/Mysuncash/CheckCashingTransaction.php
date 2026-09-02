<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;

/** A check-cashing transaction (`check_cashing_transaction`), settled via `webpos_transaction`. */
class CheckCashingTransaction extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'check_cashing_transaction';

    public $timestamps = false;

    protected $guarded = ['*'];
}
