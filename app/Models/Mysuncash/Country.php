<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;

/** Country lookup used for the Sign Up Promotion zone's "applies to" scope. */
class Country extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'country';

    protected $primaryKey = 'country_id';

    public $timestamps = false;
}
