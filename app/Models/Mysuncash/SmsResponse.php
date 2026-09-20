<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A canned outbound SMS reply template (`sms_responses`), keyed by `(merchant_id, response_title)` — `merchant_id = 0` is the "All Merchants" default, overridden per-merchant when a matching row exists. Legacy `tools/sms_responses`. */
#[Fillable(['merchant_id', 'response_title', 'message_template', 'date_created', 'last_modified'])]
class SmsResponse extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'sms_responses';

    public $timestamps = false;
}
