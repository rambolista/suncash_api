<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One island a "specific"-scope feature release applies to (`feature_release_islands`). */
#[Fillable(['release_id', 'island_id'])]
class FeatureReleaseIsland extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'feature_release_islands';

    public $timestamps = false;

    public function island(): BelongsTo
    {
        return $this->belongsTo(Island::class, 'island_id');
    }
}
