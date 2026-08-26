<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** App-wide audit trail (dozens of `log_type` values across the platform), not exclusive to any one feature. */
#[Fillable([
    'merchant_id', 'customer_id', 'user_id', 'updated_by', 'log_type', 'data',
    'user_ip_address', 'cloudflare_ip_address', 'web_channel',
])]
class WebLog extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'web_logs';

    public $timestamps = false;

    protected static function booted(): void
    {
        static::creating(function (self $log) {
            $log->updated_date = now();
        });
    }
}
