<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-sub-account limits (`sub_account_settings`) — legacy's import always
 * inserts one of these per new sub-account, leaving every column at its
 * table default (transaction_limit 500, approval-required-over-amount 50,
 * not blocked online, not school-only).
 */
#[Fillable(['customer_id', 'transaction_limit', 'is_approval_required_over_amt', 'is_blocked_online', 'is_only_school_merchant', 'merchant_restictions'])]
class SubAccountSetting extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'sub_account_settings';

    const UPDATED_AT = 'updated_at';

    const CREATED_AT = null;
}
