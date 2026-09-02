<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;

/** An events-ticket purchase header row (`ticket_transaction`), paired with `ticket_transaction_detail` via `order_id`. */
class TicketTransaction extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'ticket_transaction';

    public $timestamps = false;

    protected $guarded = ['*'];
}
