<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;

/** A kiosk/ATM location grouping (`kiosk_branch`) — several `kiosk_terminal` rows belong to one branch. */
class KioskBranch extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'kiosk_branch';

    public $timestamps = false;

    protected $guarded = ['*'];
}
