<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;

/** SMS gateway status-code lookup (`smsgateway_err_codes`) — read-only reference data, ~11 rows. */
class SmsGatewayErrCode extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'smsgateway_err_codes';

    public $timestamps = false;
}
