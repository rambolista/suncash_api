<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Audit trail row for a merchant prefund credit/debit. */
#[Fillable(['merchant_id', 'amount', 'description', 'running_balance', 'reference_no', 'trans_type', 'created_by'])]
class MerchantLedger extends Model
{
    public const TYPE_DEBIT = 1;

    public const TYPE_CREDIT = 2;

    public const TYPE_DEBIT_ADJUSTMENT = 3;

    public const TYPE_CREDIT_ADJUSTMENT = 4;

    protected $connection = 'mysuncash';

    protected $table = 'merchant_ledger';

    public $timestamps = false;

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class, 'merchant_id');
    }
}
