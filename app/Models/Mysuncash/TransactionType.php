<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;

/** Reference lookup for `client_transactions.trans_type_id` (e.g. Reload, Sale, Activation). */
class TransactionType extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'transaction_types';

    public $timestamps = false;

    protected $guarded = ['*'];
}
