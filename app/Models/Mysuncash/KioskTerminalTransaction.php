<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A kiosk deposit/adjustment ledger row (`kiosk_terminal_transactions`) — legacy Kiosk Deposits and Adjustments, and Cash Management's per-deposit lifecycle tracking. */
#[Fillable([
    'client_id', 'terminal_id', 'transaction_id', 'deposit_location', 'trans_type',
    'recyclable_deposit_dest', 'recyclable_amount', 'non_recyclable_amount',
    'deposit_store_id', 'deposit_terminal_id', 'location_at', 'deposit_status',
    'recycle_location', 'amount', 'description', 'current_balance', 'notes',
    'create_by', 'is_non_recyclable', 'held_since',
    'updated_date', 'updated_by', 'in_custody', 'is_secured', 'is_recycled',
    'in_store', 'is_verified', 'is_deposited_to_bank', 'deposit_note', 'deposit_receipt',
    'bank_account', 'bank_branch', 'account_name', 'account_no',
    'report_as_discrepancy', 'report_as_issue',
])]
class KioskTerminalTransaction extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'kiosk_terminal_transactions';

    protected $primaryKey = 'id';

    public $timestamps = false;

    protected $dates = ['create_date'];

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(KioskTerminal::class, 'terminal_id');
    }
}
