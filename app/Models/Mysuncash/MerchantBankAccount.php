<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;

/**
 * A specific bank account on file for one merchant (`merchant_bank_account`)
 * — read-only here, used only to resolve the account name shown on a
 * settlement request's detail view.
 */
class MerchantBankAccount extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'merchant_bank_account';

    public $timestamps = false;

    protected $guarded = ['*'];
}
