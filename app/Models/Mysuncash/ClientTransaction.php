<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Merchant-facing account ledger entry (mirrors transactions_model::client_transaction_registration). */
#[Fillable([
    'client_record_id', 'user_type_id', 'ref_trans_id', 'trans_type_id', 'amount', 'description',
    'timestamp', 'is_merchant', 'merchant_id', 'running_balance', 'available_balance', 'onhold_balance',
])]
class ClientTransaction extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'client_transactions';

    public $timestamps = false;

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class, 'client_record_id');
    }
}
