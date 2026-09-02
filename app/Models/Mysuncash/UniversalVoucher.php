<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;

/** A UniBucks universal voucher (`universal_vouchers`). Status: ACTIVE/REDEEMED/VOIDED/GENERATED. */
class UniversalVoucher extends Model
{
    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_VOIDED = 'VOIDED';

    protected $connection = 'mysuncash';

    protected $table = 'universal_vouchers';

    public $timestamps = false;

    protected $guarded = ['*'];
}
