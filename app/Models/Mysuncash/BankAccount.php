<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A linked bank account admins can select when processing a Cheque
 * settlement (`bank_accounts`) — a shared/house-level reference list, not
 * scoped to any one merchant (matches legacy: `link_company_bank_account()`
 * never takes a merchant id either).
 */
#[Fillable(['business_billpay_banks_id', 'account_name', 'account_no', 'status'])]
class BankAccount extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'bank_accounts';

    public $timestamps = false;

    public function businessBillpayBank(): BelongsTo
    {
        return $this->belongsTo(BusinessBillpayBank::class, 'business_billpay_banks_id');
    }
}
