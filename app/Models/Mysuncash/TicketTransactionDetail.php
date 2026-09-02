<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;

/** Line-item detail (event/venue/buyer) for an events-ticket purchase (`ticket_transaction_detail`). */
class TicketTransactionDetail extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'ticket_transaction_detail';

    public $timestamps = false;

    protected $guarded = ['*'];
}
