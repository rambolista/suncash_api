<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Western Union compliance-document submission (`wu_uploaded_request`) —
 * a customer uploads these in response to a compliance hold on a WU
 * transfer. Despite the `status` column existing (legacy admin never reads
 * or writes it; every row in production is 'PENDING'), this is a read-only
 * log: a customer can accumulate multiple submissions over time (confirmed
 * live), each its own row — NOT a 1:1 customer profile.
 */
class WuUploadedRequest extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'wu_uploaded_request';

    public $timestamps = false;

    protected $guarded = ['*'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
}
