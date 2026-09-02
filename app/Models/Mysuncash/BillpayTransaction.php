<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;

/** A bill payment transaction (`billpay_transactions`), joined via `settlement_transaction_id` to a kiosk `BILLPAY` row. Read-only. */
class BillpayTransaction extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'billpay_transactions';

    public $timestamps = false;

    protected $guarded = ['*'];
}
