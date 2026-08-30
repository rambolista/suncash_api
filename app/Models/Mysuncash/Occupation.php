<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;

/** Occupation lookup, referenced by `customers.occupation` — read-only reference data. */
class Occupation extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'occupation';

    public $timestamps = false;
}
