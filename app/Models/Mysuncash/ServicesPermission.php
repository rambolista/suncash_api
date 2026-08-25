<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Grants a merchant access to one system_services row; status 'A' = active, 'I' = inactive. */
#[Fillable(['client_record_id', 'system_services_id', 'status', 'remarks', 'user_id_create', 'user_id_modify'])]
class ServicesPermission extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'services_permission';

    public $timestamps = false;

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class, 'client_record_id');
    }

    public function systemService(): BelongsTo
    {
        return $this->belongsTo(SystemService::class, 'system_services_id');
    }
}
