<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A merchant's/customer's paired Sand Dollar wallet device (legacy `sand_dollar_auth`). */
#[Fillable([
    'is_client_merchant', 'client_id', 'device_id', 'device_no', 'auth_secret',
    'can_setup_customname', 'is_receive_only', 'type', 'custom_name', 'status',
])]
class SandDollarAuth extends Model
{
    public const STATUS_ACTIVE = 0;

    protected $connection = 'mysuncash';

    protected $table = 'sand_dollar_auth';

    public $timestamps = false;

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class, 'client_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'client_id');
    }
}
