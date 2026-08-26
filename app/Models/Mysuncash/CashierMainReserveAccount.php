<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * The Suncash-wide main reserve account; at least one APPROVED row must exist
 * before any merchant can enable store float. Managed via Float Management >
 * Main Reserve Account & Set Main Reserve Account (app/Services/FloatManagement).
 */
#[Fillable([
    'transaction_id', 'minimum_account', 'maximum_account', 'repl_amount', 'amount', 'status',
    'email_address', 'create_date', 'create_by', 'approve_date', 'approve_by',
    'rejected_date', 'rejected_by', 'updated_date', 'updated_by', 'is_confirm',
])]
class CashierMainReserveAccount extends Model
{
    public const PENDING = 'PENDING';

    public const APPROVED = 'APPROVED';

    public const REJECTED = 'REJECTED';

    protected $connection = 'mysuncash';

    protected $table = 'cashier_main_reserve_accounts';

    public $timestamps = false;
}
