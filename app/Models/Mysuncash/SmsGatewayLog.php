<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-attempt SMS/WhatsApp send audit trail (`smsgateway_logs`) — every
 * legacy gateway path (Infobip, the "aliv" SOAP carrier, the old v3 API)
 * writes one row per real send attempt here, regardless of success. This
 * is the backing table for a future "Kiosk/Settings > SMS Logs" report,
 * joined against `smsgateway_err_codes` for human-readable status
 * descriptions. `email`/`email_status` are legacy's pending-email-fallback
 * columns (queued when Infobip failed) — not written here, since that
 * fallback mechanism isn't ported.
 */
#[Fillable(['mobile', 'status_code', 'message', 'response', 'request', 'timestamp'])]
class SmsGatewayLog extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'smsgateway_logs';

    public $timestamps = false;
}
