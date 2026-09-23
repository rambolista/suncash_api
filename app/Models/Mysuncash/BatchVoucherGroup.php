<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** One uploaded batch of vouchers-to-generate (`batch_voucher_group`) — legacy `tools/voucher_batch_generation`. */
#[Fillable(['batch_name'])]
class BatchVoucherGroup extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'batch_voucher_group';

    public $timestamps = false;
}
