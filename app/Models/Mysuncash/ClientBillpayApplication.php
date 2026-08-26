<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Business or Charity's "Initial Info" application record — the detail
 * behind Business Management / Charity Management's review screen. One row
 * per merchant (`client_id`), created alongside registration.
 */
#[Fillable([
    'client_id', 'sole_proprietorship', 'name_of_parent_company', 'business_license_no', 'business_shortcode',
    'company_address', 'island', 'country', 'head_office_telephone_no1', 'head_office_telephone_no2',
    'business_email_address', 'business_website', 'primary_contact', 'p_telephone_no', 'p_email_address',
    'secondary_contact', 's_telephone_no', 's_email_address', 'name_of_primary_guarantor', 'name_of_secondary_guarantor',
    'service_categories', 'cert_issue_date', 'purpose', 'activities', 'modification_date', 'tin', 'tin_expiry',
    'sales_representative', 'business_size', 'assets_description', 'description',
    'monthly_amt_of_payments', 'monthly_frequency_of_withdrawals', 'business_license',
])]
class ClientBillpayApplication extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'client_billpay_application';

    public $timestamps = false;

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class, 'client_id');
    }
}
