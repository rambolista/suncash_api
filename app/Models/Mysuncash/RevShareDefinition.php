<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;

/**
 * How a transaction fee is split between the transacting merchant
 * (`main_client_id`) and a beneficiary entity (`share_client_id`), per
 * transaction type (`main_transaction_type_id`), as a fixed amount or
 * percentage (`share_type` 0/1) — `rev_share_definitions`. `main_client_id`/
 * `main_transaction_type_id` of `0` is a global default row. Legacy
 * `tools/revshare_management` is read-only for this table — its own
 * Add/Edit/Delete buttons have no working handler or save endpoint
 * anywhere in that codebase, so this stays read-only here too.
 */
class RevShareDefinition extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'rev_share_definitions';

    public $timestamps = false;

    protected $guarded = ['*'];
}
