<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A name blocked from Western Union transactions (legacy `wu_block_list`). */
#[Fillable(['name', 'status', 'updated_by'])]
class WuBlockList extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    protected $connection = 'mysuncash';

    protected $table = 'wu_block_list';

    public $timestamps = false;
}
