<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A kiosk branch's "partner" (`kiosk_3rdparty_users`, `user_type='partner'`)
 * — an optional settlement and/or commission payout profile for a branch.
 * Legacy's single "Add Partner / Settlement / Commission" screen; the
 * `is_stl`/`is_comm` flags and their `stl_*`/`comm_*` columns are just a
 * static payout profile, not a settlement/commission execution history.
 */
#[Fillable([
    'user_type', 'kiosk_branch_id', 'terminal_id',
    'first_name', 'middle_name', 'last_name', 'email', 'mobile', 'address',
    'stl_frequency', 'is_stl', 'stl_type', 'stl_suncash', 'stl_business_id',
    'stl_bank_type', 'stl_bank_id', 'stl_bank_branch_id', 'stl_bank_acct_name', 'stl_bank_acct_no',
    'comm_frequency', 'is_comm', 'comm_type', 'comm_suncash', 'comm_business_id',
    'comm_bank_type', 'comm_bank_id', 'comm_bank_branch_id', 'comm_bank_acct_name', 'comm_bank_acct_no',
    'status', 'created_date', 'created_by', 'updated_by', 'updated_date',
])]
class KioskThirdPartyUser extends Model
{
    public const USER_TYPE_PARTNER = 'partner';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_DELETED = 'deleted';

    protected $connection = 'mysuncash';

    protected $table = 'kiosk_3rdparty_users';

    public $timestamps = false;

    public function branch(): BelongsTo
    {
        return $this->belongsTo(KioskBranch::class, 'kiosk_branch_id');
    }
}
