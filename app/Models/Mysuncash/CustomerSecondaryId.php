<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A customer's optional secondary ID document (`customer_secondary_id`), shown only when `customers.has_secondary_id = 1`. */
class CustomerSecondaryId extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'customer_secondary_id';

    public $timestamps = false;

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
}
