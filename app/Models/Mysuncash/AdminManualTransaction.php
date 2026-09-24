<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Audit trail of Tools > Customer Debit/Credit "Process" actions (`admin_manual_transactions`), legacy's `get_process_admin_transaction()`. */
#[Fillable(['customer_id', 'admin_user_id', 'transaction_id', 'reference_id', 'trans_type_id', 'amount', 'notes', 'transaction_date'])]
class AdminManualTransaction extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'admin_manual_transactions';

    public $timestamps = false;
}
