<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One store-float account request/config for a merchant (history preserved, not overwritten). */
#[Fillable([
    'terminal_id', 'merchant_id', 'minimum_account', 'maximum_account', 'amount', 'status', 'email_address',
    'create_by', 'approve_by', 'rejected_by', 'confirm_by', 'update_by',
])]
class CashierStoreFloatAccount extends Model
{
    public const PENDING = 'PENDING';

    public const APPROVED = 'APPROVED';

    public const REJECTED = 'REJECTED';

    public const CONFIRMED = 'CONFIRMED';

    protected $connection = 'mysuncash';

    protected $table = 'cashier_store_float_accounts';

    public $timestamps = false;

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class, 'merchant_id');
    }
}
