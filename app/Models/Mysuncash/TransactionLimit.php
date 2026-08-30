<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;

/** Per-tier daily transaction limit (`transaction_limit`), keyed by `customers.customer_access`'s value (quickstart/pending/full/rejected). Read-only reference data. */
class TransactionLimit extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'transaction_limit';

    public $timestamps = false;
}
