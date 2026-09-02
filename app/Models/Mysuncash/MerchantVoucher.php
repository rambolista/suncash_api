<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;

/** A SunCash merchant-issued voucher (`merchant_vouchers`). Status: ACTIVE/REDEEMED/VOIDED/GENERATED. */
class MerchantVoucher extends Model
{
    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_VOIDED = 'VOIDED';

    protected $connection = 'mysuncash';

    protected $table = 'merchant_vouchers';

    public $timestamps = false;

    protected $guarded = ['*'];
}
