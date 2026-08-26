<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A school/charity merchant's sub-account roster row (`sub_accounts`) —
 * backs Business Management's "Sub Account" bulk-upload button. One row
 * per imported student, linking a merchant (`client_id`) to the `customers`
 * record created for them (`sub_customer_id`).
 */
#[Fillable([
    'client_id', 'sub_customer_id', 'student_id_number', 'mobile',
    'parent_1_email', 'parent_1_phone', 'parent_1_name',
    'parent_2_email', 'parent_2_phone', 'parent_2_name',
    'parent_3_email', 'parent_3_phone', 'parent_3_name',
    'parent_4_email', 'parent_4_phone', 'parent_4_name',
])]
class SubAccount extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'sub_accounts';

    const CREATED_AT = 'created_at';

    const UPDATED_AT = 'updated_at';

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'sub_customer_id');
    }
}
