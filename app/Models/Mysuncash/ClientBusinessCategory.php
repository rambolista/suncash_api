<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;

/** Service-category lookup for Business's Initial Info screen (Restaurant, Retail, etc.) — read-only reference data. */
class ClientBusinessCategory extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'client_business_categories';

    public $timestamps = false;
}
