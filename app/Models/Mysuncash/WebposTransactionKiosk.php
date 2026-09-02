<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;

/** A kiosk terminal's customer transaction log (`webpos_transaction_kiosk`) — the primary source for the Kiosk Statement ledger. Read-only. */
class WebposTransactionKiosk extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'webpos_transaction_kiosk';

    public $timestamps = false;

    protected $guarded = ['*'];
}
