<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;

/** A merchant-to-charity donation (`donate_transactions`), settled via `webpos_transaction`. */
class DonateTransaction extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'donate_transactions';

    public $timestamps = false;

    protected $guarded = ['*'];
}
