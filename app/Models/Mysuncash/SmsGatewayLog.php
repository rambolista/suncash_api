<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-attempt SMS/WhatsApp send audit trail (`smsgateway_logs`) — every
 * legacy gateway path (Infobip, the "aliv" SOAP carrier, the old v3 API)
 * writes one row per real send attempt here, regardless of success. Backs
 * "Tools > SMS Logs", joined against `smsgateway_err_codes` for
 * human-readable status descriptions. `email`/`email_status` are legacy's
 * pending-email-fallback columns (queued when Infobip failed) — not written
 * here, since that fallback mechanism isn't ported.
 */
#[Fillable(['mobile', 'status_code', 'message', 'response', 'request', 'timestamp'])]
class SmsGatewayLog extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'smsgateway_logs';

    public $timestamps = false;

    public function errCode(): BelongsTo
    {
        return $this->belongsTo(SmsGatewayErrCode::class, 'status_code', 'status_code');
    }
}
