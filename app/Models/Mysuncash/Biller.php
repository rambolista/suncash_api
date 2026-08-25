<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Short-code registration for entity_type 3 (Biller) merchants. */
#[Fillable(['biller_code', 'biller_name', 'client_record_id', 'fee_amount', 'date_created'])]
class Biller extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'billers';

    public $timestamps = false;

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class, 'client_record_id');
    }
}
