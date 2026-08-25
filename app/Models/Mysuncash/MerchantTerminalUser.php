<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A merchant's POS/branch portal login (legacy "POS Users" screen). */
#[Fillable([
    'merchant_id', 'first_name', 'last_name', 'location', 'user_name', 'password', 'user_status_id',
    'creation_date', 'modification_date', 'branch_id', 'branch_user_type_id',
    'created_by', 'ALL_ACCESS_BRANCH', 'require_pw_change', 'application_access', 'update_by',
])]
#[Hidden(['password'])]
class MerchantTerminalUser extends Model
{
    public const BRANCH_USER_TYPES = [
        1 => 'Branch Admin',
        2 => 'Branch User',
        3 => 'HQ User',
        4 => 'HQ Admin',
        5 => 'Branch Supervisor',
    ];

    protected $connection = 'mysuncash';

    protected $table = 'merchant_terminal_users';

    public $timestamps = false;

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class, 'merchant_id', 'id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }
}
