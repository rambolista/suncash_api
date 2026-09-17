<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A recorded prize win (`promo_winners`) — written when a draw (grand/weekly/instant) picks a customer. `draw_date` defaults to CURRENT_TIMESTAMP at the DB level. */
#[Fillable(['ticket_id', 'customer_id', 'promo_id', 'prize_type', 'promo_type', 'status'])]
class PromoWinner extends Model
{
    public const STATUS_WON = 'WON';

    public const STATUS_CLAIMED = 'CLAIMED';

    public const PRIZE_TYPE_ITEM = 'item';

    public const PRIZE_TYPE_CASH = 'cash';

    protected $connection = 'mysuncash';

    protected $table = 'promo_winners';

    public $timestamps = false;
}
