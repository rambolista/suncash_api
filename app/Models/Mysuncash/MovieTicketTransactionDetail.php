<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;

/** Line-item detail (movie/showtime/venue) for a movie-ticket purchase (`movie_ticket_transaction_detail`). */
class MovieTicketTransactionDetail extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'movie_ticket_transaction_detail';

    public $timestamps = false;

    protected $guarded = ['*'];
}
