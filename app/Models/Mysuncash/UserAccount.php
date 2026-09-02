<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * The legacy `user_account` table is shared by every login type (admin,
 * merchant, cardholder, kiosk...) distinguished by user_type_id. This model
 * is used here only for merchant portal logins (user_type_id = 1).
 */
#[Fillable([
    'first_name', 'last_name', 'user_type_id', 'user_reference', 'user_name', 'password', 'user_status_id',
    'user_id_create', 'user_id_modified', 'require_pw_change', 'pw_expiration', 'creation_date', 'modification_date',
    'email_address', 'mobile_number', 'role', 'tp_user_reference',
])]
#[Hidden(['password'])]
class UserAccount extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'user_account';

    public $timestamps = false;

    public function userKey(): HasOne
    {
        return $this->hasOne(UserKey::class, 'user_id');
    }
}
