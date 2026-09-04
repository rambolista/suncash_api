<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;

/** Kiosk product/transaction-type catalog (`kiosk_products`) — `product_code` is the vocabulary `kiosk_profiles.kiosk_product_id` and commission math match against (e.g. via `kiosk_terminal`/`kiosk_profiles` joins). Read-only. */
class KioskProduct extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'kiosk_products';

    public $timestamps = false;

    protected $guarded = ['*'];
}
