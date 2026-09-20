<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;

/** A device the customer's mobile app has registered from (`customers_uuid`) — legacy's "Authorized Devices" list. */
class CustomerDevice extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'customers_uuid';

    public $timestamps = false;

    protected $guarded = ['*'];
}
