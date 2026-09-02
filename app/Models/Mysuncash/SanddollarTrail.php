<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;

/** A Sand Dollar mobile-wallet transaction (`sanddollar_trail`), joined via `transaction_id` to kiosk SEND/LOAD_SANDDOLLAR rows. Read-only. */
class SanddollarTrail extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'sanddollar_trail';

    public $timestamps = false;

    protected $guarded = ['*'];
}
