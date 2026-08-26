<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;

/** Government ID type lookup (Driver's License, Passport, etc.) for a Business owner's identification — read-only reference data. */
class IdType extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'id_type';

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;
}
