<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;

/** A single checkout-flow log line (`suncashme_checkout_logs`) — read-only here, only ever written by the checkout flow itself. */
class CheckoutLog extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'suncashme_checkout_logs';

    public $timestamps = false;
}
