<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Approval-workflow log row for a float replenishment request — either a
 * store float replenishment/setup (`is_main_reserve = 0`) or a main reserve
 * account replenishment (`is_main_reserve = 1`).
 */
#[Fillable([
    'transaction_id', 'merchant_id', 'terminal_id', 'amount', 'status', 'create_date', 'create_by',
    'approve_date', 'approve_by', 'rejected_date', 'rejected_by', 'confirm_by', 'confirm_date',
    'is_main_reserve', 'is_webpos_request',
])]
class CashierAccountReplenishment extends Model
{
    public const PENDING = 'PENDING';

    public const APPROVED = 'APPROVED';

    public const REJECTED = 'REJECTED';

    public const CONFIRMED = 'CONFIRMED';

    protected $connection = 'mysuncash';

    protected $table = 'cashier_account_replenishment';

    public $timestamps = false;

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class, 'merchant_id');
    }
}
