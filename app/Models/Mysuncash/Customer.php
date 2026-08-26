<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The legacy `customers` table — SunCash card/wallet holders on the
 * customer-facing app. Unrelated to `App\Models\Customer`, which is the
 * new system's own separately-run customer portal.
 */
#[Fillable([
    'first_name', 'last_name', 'gender', 'birthday', 'address1',
    'is_sub_account', 'is_new', 'status', 'country', 'ezkard_account_id', 'merchant_id',
])]
class Customer extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'customers';

    public $timestamps = false;

    public function islandRecord(): BelongsTo
    {
        return $this->belongsTo(Island::class, 'island');
    }
}
