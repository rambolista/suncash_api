<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;

/** An international gift card sale (`mgo_giftcard_transactions`), joined via `transaction_id` to a kiosk `MGODIGITALSALES` row. Read-only. */
class MgoGiftcardTransaction extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'mgo_giftcard_transactions';

    public $timestamps = false;

    protected $guarded = ['*'];
}
