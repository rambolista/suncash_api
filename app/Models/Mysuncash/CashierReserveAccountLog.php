<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Append-only audit log of every float credit/debit action (topups, replenishments). */
#[Fillable(['reserve_request_id', 'purpose', 'amount', 'create_date', 'create_by'])]
class CashierReserveAccountLog extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'cashier_reserve_account_logs';

    public $timestamps = false;
}
