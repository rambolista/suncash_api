<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Named commission profile templates (`kiosk_profiles`) — `kiosk_terminal.profile_id`
 * stores a `profile_name`, not this table's numeric id. One row per
 * (profile_name, kiosk_product_id) pair; "a profile" in the admin UI means
 * every row sharing a `profile_name`.
 */
#[Fillable([
    'profile_name', 'kiosk_product_id', 'manager_id',
    'provider_percentage', 'cap_amount', 'minimum_amount', 'frequency_in_limit_days',
    'agent_percentage', 'suncash_percentage', 'owner_percentage',
    'create_date', 'create_by', 'update_date', 'update_by',
])]
class KioskProfile extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'kiosk_profiles';

    public $timestamps = false;
}
