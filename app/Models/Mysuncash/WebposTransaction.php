<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;

/**
 * The universal POS/webpos transaction log (`webpos_transaction`) — most
 * non-card-ledger transaction types (Money Transfer, Cashout, Donation,
 * Check Cashing, Billpay, ...) write one header row here alongside their own
 * type-specific detail table. `status = '1'` means voided.
 */
class WebposTransaction extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'webpos_transaction';

    public $timestamps = false;

    protected $guarded = ['*'];
}
