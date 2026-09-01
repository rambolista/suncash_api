<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A customer app login/link attempt (`customer_login_logs`), SUCCESS or FAILED. */
class CustomerLoginLog extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'customer_login_logs';

    public $timestamps = false;

    protected $guarded = ['*'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
}
