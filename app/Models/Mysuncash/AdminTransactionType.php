<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;

/** Dropdown source for Tools > Customer Debit/Credit's "Transaction Type Selector" (`admin_transaction_types`). */
class AdminTransactionType extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'admin_transaction_types';

    public $timestamps = false;

    protected $guarded = ['*'];
}
