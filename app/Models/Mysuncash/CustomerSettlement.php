<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer's bank-withdrawal request (`customer_settlements`) — legacy's
 * `Administrator::customer_settlement()`. Requests are created by the
 * customer app/kiosk channels (outside this codebase); this side only
 * reviews, processes, or rejects them.
 */
#[Fillable([
    'transaction_reference_id', 'transaction_id', 'message', 'status',
    'proccessed_date', 'proccessed_by', 'updated_date', 'updated_by',
    'check_number', 'is_check_signed', 'rejected_date', 'rejected_by',
    'customer_id', 'origin_id', 'transaction_type', 'linked_bank_branch_id',
    'amount', 'total_amount', 'fee', 'withdrawal_type', 'account_type',
    'customer_number', 'channel', 'created_date', 'created_by',
])]
class CustomerSettlement extends Model
{
    public const STATUS_PENDING = 'PENDING';

    public const STATUS_PROCESSED = 'PROCESSED';

    public const STATUS_REJECTED = 'REJECTED';

    protected $connection = 'mysuncash';

    protected $table = 'customer_settlements';

    public $timestamps = false;

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function linkedBank(): BelongsTo
    {
        return $this->belongsTo(CustomerBank::class, 'linked_bank_branch_id');
    }
}
