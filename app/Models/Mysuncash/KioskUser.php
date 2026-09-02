<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A physical-kiosk-terminal login account (`kiosk_users`) — used by kiosk
 * staff to authenticate on the terminal's own "Zout" cash-reconciliation
 * screen (a separate legacy `services` app this codebase doesn't host), NOT
 * to log into this admin panel. `status`: 0 = active, 1 = soft-deleted.
 * `admin_user_id` links to a promoted `user_account` row (-1 = none) — see
 * `KioskUserService` for the promote/demote flow between this table and
 * `user_account` (role=3, the "Kiosk Admin User" type).
 */
#[Fillable(['branch_id', 'admin_user_id', 'first_name', 'last_name', 'username', 'password', 'email_address', 'create_date', 'create_by', 'update_date', 'update_by', 'status'])]
#[Hidden(['password'])]
class KioskUser extends Model
{
    public const STATUS_ACTIVE = 0;

    public const STATUS_DELETED = 1;

    protected $connection = 'mysuncash';

    protected $table = 'kiosk_users';

    public $timestamps = false;

    public function branch(): BelongsTo
    {
        return $this->belongsTo(KioskBranch::class, 'branch_id');
    }
}
