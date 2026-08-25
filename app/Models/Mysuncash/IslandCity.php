<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Island-city lookup, used for the Branch address form. */
class IslandCity extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'island_city';

    protected $primaryKey = 'city_id';

    public $timestamps = false;

    public function island(): BelongsTo
    {
        return $this->belongsTo(Island::class, 'island_id');
    }
}
