<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An owner/director/executive officer declared on a Business's Initial Info screen. Business Management only — Charity has no equivalent. */
#[Fillable([
    'client_record_id', 'owner_name', 'dob', 'mobile_number', 'id_number', 'id_type', 'expiry_date',
    's_id_number', 's_id_type', 's_expiry_date', 'position_level', 'signatory_rights', 'ownership',
    'created_date', 'updated_date',
])]
class MerchantOwner extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'business_other_info';

    public $timestamps = false;

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class, 'client_record_id');
    }
}
