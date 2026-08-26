<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A merchant's hosted checkout/payment-widget configuration
 * (`widget_setup`). Business Management's "Voucher Setting" button only
 * touches `voucher_product` — the callback and url columns belong to a
 * different, not-yet-ported feature.
 */
#[Fillable(['client_id', 'voucher_product'])]
class WidgetSetup extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'widget_setup';

    const CREATED_AT = 'created_date';

    const UPDATED_AT = 'updated_date';
}
