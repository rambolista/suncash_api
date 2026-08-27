<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Ties a `client_transactions` ledger row to which balance bucket it moved
 * (0=prefund, 1=settlement, 2=fee) — mirrors legacy's
 * clients_model::_adjust_client_balance().
 */
#[Fillable(['client_transaction_id', 'client_account_type', 'client_record_id', 'amount'])]
class ClientTransactionDetail extends Model
{
    public const ACCOUNT_TYPE_PREFUND = 0;

    public const ACCOUNT_TYPE_SETTLEMENT = 1;

    public const ACCOUNT_TYPE_FEE = 2;

    protected $connection = 'mysuncash';

    protected $table = 'client_transaction_details';

    const CREATED_AT = 'timestamp';

    const UPDATED_AT = null;
}
