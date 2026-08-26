<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;

/** Country lookup used specifically by client_billpay_application (Business/Charity Initial Info) — a separate `countries` table from the `country` one Promotions uses. */
class BillpayCountry extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'countries';

    public $timestamps = false;
}
