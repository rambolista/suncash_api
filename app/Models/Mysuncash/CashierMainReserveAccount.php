<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;

/** The Suncash-wide main reserve account; at least one APPROVED row must exist before any merchant can enable store float. */
class CashierMainReserveAccount extends Model
{
    public const APPROVED = 'APPROVED';

    protected $connection = 'mysuncash';

    protected $table = 'cashier_main_reserve_accounts';

    public $timestamps = false;
}
