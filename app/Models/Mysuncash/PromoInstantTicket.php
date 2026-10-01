<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A manually-assigned instant-prize winner (`promo_instant_tickets`) — legacy's "Instant Winners" tool. */
#[Fillable(['ticket_id', 'prize_id', 'prize_type', 'promo_type', 'status', 'created_by', 'updated_by', 'updated_date'])]
class PromoInstantTicket extends Model
{
    public const STATUS_ACTIVE = 'ACTIVE';

    protected $connection = 'mysuncash';

    protected $table = 'promo_instant_tickets';

    public $timestamps = false;
}
