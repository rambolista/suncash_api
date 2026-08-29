<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Business Billpay payment request (`business_bill_transaction`) — backs
 * the "Business Billpay" admin queue. Requests are created by the Business
 * Portal (outside this codebase); this side only reviews and approves/
 * rejects the ones with `source_app = 'BusinessBillpay'`.
 *
 * `status` here is a DIFFERENT vocabulary than `ManualSettlement::status`:
 * 'A' = Pending (awaiting review), 'P' = Processed (approved), 'R' = Rejected.
 */
#[Fillable(['status', 'update_by', 'update_date'])]
class BusinessBillTransaction extends Model
{
    public const STATUS_PENDING = 'A';

    public const STATUS_PROCESSED = 'P';

    public const STATUS_REJECTED = 'R';

    public const SOURCE_APP = 'BusinessBillpay';

    protected $connection = 'mysuncash';

    protected $table = 'business_bill_transaction';

    protected $primaryKey = 'transaction_id';

    public $timestamps = false;

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class, 'merchant_client_id');
    }

    public function payor(): BelongsTo
    {
        return $this->belongsTo(Merchant::class, 'payor_client_id');
    }

    public function payeeCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'payee_customer_id');
    }
}
