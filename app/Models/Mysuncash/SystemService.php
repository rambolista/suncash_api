<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Master catalog of services a merchant can be granted access to (e.g. Bills Payment, TopUp, Gaming). */
class SystemService extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'system_services';

    public $timestamps = false;

    public function permissions(): HasMany
    {
        return $this->hasMany(ServicesPermission::class, 'system_services_id');
    }
}
