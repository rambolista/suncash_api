<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A geo-fenced Sign Up Promotion zone — a polygon plus the bonus it pays new signups inside it. */
#[Fillable([
    'promo_type', 'promo_amount', 'promo_description', 'promo_country',
    'create_date', 'update_date', 'updated_by', 'date_from', 'date_to', 'coordinates', 'status',
])]
class GeoPromo extends Model
{
    public const STATUS_ACTIVE = 0;

    public const STATUS_DELETED = 1;

    protected $connection = 'mysuncash';

    protected $table = 'geo_promo';

    public $timestamps = false;
}
