<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recipient row within a Customer Benefits Distribution batch (`customer_benefits`).
 * `issued_id` is the recipient's NIB number, matched against `customers.nib_number` to
 * find the account to credit. `receiver_customer_id`/`date_processed` exist on the table
 * but are never populated by legacy either — left alone here too.
 */
#[Fillable(['name', 'mobile', 'email', 'amount', 'issuer', 'issued_id', 'status', 'batch_id'])]
class CustomerBenefit extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAID = 'paid';

    protected $connection = 'mysuncash';

    protected $table = 'customer_benefits';

    public $timestamps = false;

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CustomerBenefitBatch::class, 'batch_id');
    }
}
