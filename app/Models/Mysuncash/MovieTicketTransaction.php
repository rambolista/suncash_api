<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;

/** A movie-ticket purchase header row (`movie_ticket_transaction`), paired with `movie_ticket_transaction_detail`. */
class MovieTicketTransaction extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'movie_ticket_transaction';

    public $timestamps = false;

    protected $guarded = ['*'];
}
