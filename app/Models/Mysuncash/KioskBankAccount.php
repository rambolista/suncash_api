<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Kiosk-channel bank account (`kiosk_bank_accounts`). Two distinct uses
 * share this table: `user_type='customer'` rows are a customer's own linked
 * bank account, encrypted at rest and keyed off `customer_number` (see
 * `CustomerSettlementService::decryptPan()`); `user_type='terminal_user'`
 * rows (Kiosk Management's "Manage Bank Account") are branch-level payout
 * accounts (`terminal_branch_id`), written in PLAINTEXT by legacy — do not
 * route those through `CustomerSettlementService`, they were never encrypted.
 */
#[Fillable([
    'customer_number', 'bank_id', 'branch_id', 'bank_name', 'branch_name',
    'account_number', 'account_type', 'customer_name', 'bank_logo',
    'status', 'user_type', 'terminal_id', 'terminal_branch_id',
    'create_date', 'created_by', 'update_date', 'updated_by',
])]
class KioskBankAccount extends Model
{
    public const USER_TYPE_TERMINAL = 'terminal_user';

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_DELETED = 'DELETED';

    protected $connection = 'mysuncash';

    protected $table = 'kiosk_bank_accounts';

    public $timestamps = false;

    public function branch(): BelongsTo
    {
        return $this->belongsTo(KioskBranch::class, 'terminal_branch_id');
    }
}
