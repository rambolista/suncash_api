<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A card-balance ledger entry (`ezkard_transactions`) — legacy's
 * tools_model::_adjust_card_account() writes one of these alongside every
 * `ezkard_accounts.card_balance` change. Columns are legacy-typed as
 * strings even where numeric.
 */
#[Fillable([
    'merchant_id', 'ezkard_id', 'terminal_id', 'transaction_id', 'amount', 'trans_type_id',
    'description', 'reference_id', 'timestamp', 'trans_status_id', 'running_balance', 'merchant_terminal_users_id',
])]
class EzkardTransaction extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'ezkard_transactions';

    public $timestamps = false;
}
