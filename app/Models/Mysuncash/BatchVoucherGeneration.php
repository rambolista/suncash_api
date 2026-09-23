<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One recipient row within a voucher batch (`batch_voucher_generation`).
 * `status`: 0 = unprocessed, 1 = processed (voucher generated), 2 = skipped.
 * `field1`/`field2` are legacy's names for issuer / issued id.
 */
#[Fillable(['name', 'mobile', 'email', 'amount', 'field1', 'field2', 'voucher_number', 'pin', 'status', 'batch_id'])]
class BatchVoucherGeneration extends Model
{
    public const STATUS_UNPROCESSED = 0;

    public const STATUS_PROCESSED = 1;

    public const STATUS_SKIPPED = 2;

    protected $connection = 'mysuncash';

    protected $table = 'batch_voucher_generation';

    public $timestamps = false;
}
