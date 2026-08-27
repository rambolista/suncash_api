<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A merchant-facing statement row (`merchant_transaction_history`) — one
 * per money movement, with a `running_balance` snapshot. Mirrors legacy's
 * clients_model::merchant_universal_transaction_history().
 */
#[Fillable(['client_record_id', 'system_services_code', 'type', 'source', 'destination', 'description', 'amount', 'reference', 'running_balance'])]
class MerchantTransactionHistory extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'merchant_transaction_history';

    const CREATED_AT = 'timestamp';

    const UPDATED_AT = null;
}
