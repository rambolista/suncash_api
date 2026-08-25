<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'client_record_id', 'main_transaction_type_id', 'transaction_fee',
    'commission_per_transaction', 'charge_to', 'date_created', 'created_by',
])]
class MerchantFeeCommission extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'merchant_fees_and_commissions';

    public $timestamps = false;

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class, 'client_record_id');
    }
}
