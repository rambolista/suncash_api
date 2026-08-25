<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Bahamas island lookup, used for the Branch address form. */
class Island extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'island';

    public $timestamps = false;

    public function cities(): HasMany
    {
        return $this->hasMany(IslandCity::class, 'island_id');
    }
}
