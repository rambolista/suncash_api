<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'client_record_id', 'date_created', 'modification_date',
    'billing_address', 'billing_city', 'billing_postalcode', 'business_license_number',
    'payment_mode', 'address1', 'address2', 'city', 'postalcode', 'country',
    'contactmobile', 'contactemail', 'contactname',
    'bank_name', 'bank_branch', 'account_name', 'account_number', 'account_type', 'routing_number',
    'locations', 'alert_amount', 'revenue_share', 'logo',
    'via_sms', 'sms_daily', 'sms_weekly', 'sms_monthly', 'sms_primary', 'sms_secondary',
    'via_email', 'email_daily', 'email_weekly', 'email_monthly', 'email_primary', 'email_secondary',
    'via_hardcopy', 'hardcopy_daily', 'hardcopy_weekly', 'hardcopy_monthly', 'hardcopy_address',
    'alert_sms', 'alert_sms_hour', 'alert_sms_recipients',
    'alert_email', 'alert_email_hour', 'alert_email_recipients',
])]
class MerchantDetail extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'merchant_details';

    public $timestamps = false;

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class, 'client_record_id');
    }
}
