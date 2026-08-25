<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One principal/officer record per merchant (merchant_id here is clients.id, despite the column name). */
#[Fillable(['merchant_id', 'fname', 'lname', 'position', 'equity', 'email', 'mobile', 'address1', 'address2', 'city', 'state', 'zip'])]
class MerchantPrincipalInfo extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'merchant_principal_info';

    public $timestamps = false;

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class, 'merchant_id');
    }
}
