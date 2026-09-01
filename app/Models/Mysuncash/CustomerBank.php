<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer's linked bank account (`customer_banks`) used as a withdrawal
 * destination. `account_name`/`account_number` are encrypted at rest — see
 * `CustomerSettlementService::decryptPan()`.
 */
#[Fillable(['card_status', 'update_date'])]
class CustomerBank extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'customer_banks';

    public $timestamps = false;

    public function businessBillpayBank(): BelongsTo
    {
        return $this->belongsTo(BusinessBillpayBank::class, 'branch_id');
    }
}
