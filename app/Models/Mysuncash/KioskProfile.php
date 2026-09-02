<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;

/** Named commission profile templates (`kiosk_profiles`) — `kiosk_terminal.profile_id` stores a `profile_name`, not this table's numeric id. */
class KioskProfile extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'kiosk_profiles';

    public $timestamps = false;

    protected $guarded = ['*'];
}
