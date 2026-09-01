<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Reference bank/branch list (`business_billpay_banks`) used across settlement bank pickers. */
class BusinessBillpayBank extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'business_billpay_banks';

    public $timestamps = false;

    protected $guarded = ['*'];

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(BankAccount::class, 'business_billpay_banks_id');
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class, 'bank_id');
    }
}
