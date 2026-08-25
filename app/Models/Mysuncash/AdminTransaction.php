<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Admin-side audit log entry (mirrors transactions_model::admin_transaction_registration). */
#[Fillable(['client_record_id', 'trans_type_id', 'amount', 'description', 'timestamp', 'admin_user_id'])]
class AdminTransaction extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'admin_transactions';

    public $timestamps = false;

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class, 'client_record_id');
    }
}
