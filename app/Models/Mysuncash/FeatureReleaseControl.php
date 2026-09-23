<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A "Feature Release" control row (`feature_release_control`) gating a feature by date and optionally by island. */
#[Fillable(['feature_type', 'scope', 'release_date', 'status', 'created_by', 'updated_by'])]
class FeatureReleaseControl extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const SCOPE_ALL = 'all';

    public const SCOPE_SPECIFIC = 'specific';

    protected $connection = 'mysuncash';

    protected $table = 'feature_release_control';

    public function islands(): HasMany
    {
        return $this->hasMany(FeatureReleaseIsland::class, 'release_id');
    }
}
