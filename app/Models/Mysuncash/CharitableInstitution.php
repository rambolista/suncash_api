<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Short-code registration for entity_type 4 (Charitable Institution) merchants. */
#[Fillable(['charity_code', 'charity_name', 'client_record_id', 'date_created'])]
class CharitableInstitution extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'charitable_institutions';

    public $timestamps = false;

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class, 'client_record_id');
    }
}
