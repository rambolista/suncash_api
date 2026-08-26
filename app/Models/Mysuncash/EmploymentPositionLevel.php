<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;

/** Position-level lookup (Entry Level, Owner, etc.) for a Business owner's role — read-only reference data. */
class EmploymentPositionLevel extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'employment_position_level';

    public $timestamps = false;
}
