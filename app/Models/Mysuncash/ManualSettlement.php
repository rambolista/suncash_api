<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A merchant's withdrawal/settlement request (`manual_settlement`) — backs
 * the "Merchant Settlements" admin queue. Rows are created by the merchant
 * portal (outside this codebase); the admin side here only reviews and
 * approves/rejects them. `status`: P(ending)/A(pproved)/R(ejected) — a
 * fourth status, 'C'(ancelled), is set by the merchant portal directly and
 * never surfaced in this admin queue (mirrors legacy).
 */
#[Fillable([
    'client_record_id', 'type', 'payee', 'payor', 'bank', 'amount', 'destination', 'status', 'channel',
    'reference', 'created_by', 'updated_date', 'updated_by', 'bank_branch', 'message_to_business',
    'approved_by', 'approved_date', 'rejected_by', 'rejected_date', 'account_number', 'bank_trans_id',
    'is_process', 'check_number', 'business_billpay_banks_id', 'account_number_transfered', 'fee',
    'withdrawal_type', 'account_type', 'first_withdrawal', 'bank_account_id',
])]
class ManualSettlement extends Model
{
    public const STATUS_PENDING = 'P';

    public const STATUS_APPROVED = 'A';

    public const STATUS_REJECTED = 'R';

    protected $connection = 'mysuncash';

    protected $table = 'manual_settlement';

    const CREATED_AT = 'created_date';

    const UPDATED_AT = null;

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class, 'client_record_id');
    }
}
