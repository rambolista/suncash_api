<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Compliance sanctions/watch list (`blocked_list`) — legacy `tools/compliance`. Also referenced by Customer Management's PEP flag (type=PEP). */
#[Fillable(['name', 'other_info', 'status', 'remarks', 'type', 'type_desc', 'created_by', 'updated_by'])]
class BlockedListEntry extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'blocked_list';
}
