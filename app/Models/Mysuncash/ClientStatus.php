<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Lookup table: id 0 => active, 1 => inactive (clients.client_status_id). */
class ClientStatus extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'client_status';

    public $timestamps = false;

    public function merchants(): HasMany
    {
        return $this->hasMany(Merchant::class, 'client_status_id');
    }
}
