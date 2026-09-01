<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;

/** Reference bank list (`bank`) — distinct from `business_billpay_banks`, which pairs a bank with a specific branch. */
class Bank extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'bank';

    public $timestamps = false;

    protected $guarded = ['*'];
}
