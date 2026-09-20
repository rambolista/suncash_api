<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** An append-only forex rate history row (`forex_rates`) — legacy `tools/forex`. The latest row per currency pair (by `timestamp`) is read live by real cross-currency transactions elsewhere in this system, so a new row here takes effect immediately. */
#[Fillable(['from_currency', 'to_currency', 'rate', 'timestamp'])]
class ForexRate extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'forex_rates';

    public $timestamps = false;
}
