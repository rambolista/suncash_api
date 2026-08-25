<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * The legacy `clients` table — despite the name, this is the merchant
 * identity record the whole mysuncash-stage platform (admin, merchant
 * portal, transaction processing) is built around.
 */
#[Fillable([
    'client_id', 'user_name', 'password', 'client_prefund', 'client_settlement',
    'client_status_id', 'user_id_create', 'user_id_modify', 'creation_date', 'modification_date',
    'legal_name', 'dba_name', 'tax_id', 'merchant_name', 'phone_no', 'reseller_name', 'reseller_type',
    'merchant_key', 'fax_no', 'is_ezpay', 'ezpay_access', 'merchant_type_id', 'registration_status', 'suntag_shortcode',
])]
class Merchant extends Model
{
    public const ENTITY_TYPES = [
        1 => 'Merchant',
        2 => 'Supplier',
        3 => 'Biller',
        4 => 'Charitable Institutions',
        5 => 'Business',
        6 => 'Charity',
    ];

    public const MAIN_TRANSACTION_TYPES = [
        1 => 'POS Card Registration',
        2 => 'POS Card Loading (Same Merchant)',
        3 => 'POS Card Loading (Different Merchant)',
        4 => 'POS Cash Out (Same Merchant)',
        5 => 'POS Cash Out (Different Merchant)',
        6 => 'POS Balance',
        7 => 'POS Purchase (Same Merchant)',
        8 => 'POS Purchase (Different Merchant)',
        9 => 'Maintenance Fee',
        10 => 'Card to Card Transfer Local',
        11 => 'Card to Card Transfer International',
        12 => 'Card Replacement',
        13 => 'Bills Payment',
        14 => 'Online Shopping',
        15 => 'SMS Inquiries',
        16 => 'ATM Withdrawal',
        20 => 'Donate',
        21 => 'Paycode',
        23 => 'SMS Forex',
        24 => 'SMS Card Linking',
        25 => 'SMS GETLC',
        26 => 'SMS Report Lost/Stolen',
        28 => 'SMS Unlink',
        29 => 'SMS Balance',
    ];

    protected $connection = 'mysuncash';

    protected $table = 'clients';

    /** Legacy timestamp columns don't follow Eloquent's created_at/updated_at convention. */
    public $timestamps = false;

    public function merchantDetail(): HasOne
    {
        return $this->hasOne(MerchantDetail::class, 'client_record_id');
    }

    public function feeCommissions(): HasMany
    {
        return $this->hasMany(MerchantFeeCommission::class, 'client_record_id');
    }

    /** The merchant's own portal login (user_account.user_type_id = 1). */
    public function userAccount(): HasOne
    {
        return $this->hasOne(UserAccount::class, 'user_reference')->where('user_type_id', 1);
    }

    public function adminTransactions(): HasMany
    {
        return $this->hasMany(AdminTransaction::class, 'client_record_id');
    }

    public function clientTransactions(): HasMany
    {
        return $this->hasMany(ClientTransaction::class, 'client_record_id');
    }

    public function biller(): HasOne
    {
        return $this->hasOne(Biller::class, 'client_record_id');
    }

    public function charitableInstitution(): HasOne
    {
        return $this->hasOne(CharitableInstitution::class, 'client_record_id');
    }

    public function clientStatus(): BelongsTo
    {
        return $this->belongsTo(ClientStatus::class, 'client_status_id');
    }

    public function principalInfo(): HasOne
    {
        return $this->hasOne(MerchantPrincipalInfo::class, 'merchant_id');
    }

    public function servicesPermissions(): HasMany
    {
        return $this->hasMany(ServicesPermission::class, 'client_record_id');
    }

    /** All portal logins for this merchant (the default account plus any staff sub-users), all user_type_id = 1. */
    public function userAccounts(): HasMany
    {
        return $this->hasMany(UserAccount::class, 'user_reference')->where('user_type_id', 1);
    }
}
