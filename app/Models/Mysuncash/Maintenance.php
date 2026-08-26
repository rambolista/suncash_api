<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Generic named feature-flag table (`channel` -> `under_maintenance` on/off), used app-wide. */
#[Fillable(['channel', 'under_maintenance', 'msg'])]
class Maintenance extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'maintenance';

    public $timestamps = false;
}
