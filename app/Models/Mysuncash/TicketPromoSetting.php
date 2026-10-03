<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Free raffle-ticket prize for a promo (legacy Tools > ticket_promo_settings): how many tickets each winner gets, how many winners, and the draw date. */
#[Fillable([
    'ticket_count', 'quantity', 'remaining_quantity', 'description', 'promo_type', 'service', 'service_ref',
    'draw_type', 'target_group_type', 'target_group', 'draw_date', 'created_date', 'updated_date', 'status',
])]
class TicketPromoSetting extends Model
{
    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_DELETED = 'DELETED';

    protected $connection = 'mysuncash';

    protected $table = 'ticket_promo_settings';

    public $timestamps = false;
}
