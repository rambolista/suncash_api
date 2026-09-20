<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * The legacy `customers` table — SunCash card/wallet holders on the
 * customer-facing app. Unrelated to `App\Models\Customer`, which is the
 * new system's own separately-run customer portal.
 */
#[Fillable([
    'first_name', 'last_name', 'middle_name', 'gender', 'birthday', 'address1', 'address2', 'zip',
    'is_sub_account', 'is_new', 'status', 'country', 'ezkard_account_id', 'merchant_id',
    'customer_access', 'reason_reject', 'updated_by', 'updated_on', 'mobile', 'reward_identifier',
    'email', 'island', 'city', 'customer_tag', 'risk_rating', 'occupation', 'employment_position_level',
    'sms_notification', 'email_notification', 'is_locked', 'is_card_beta_user',
    'restricted_by', 'restricted_date', 'restricted_reason', 'restricted_note', 'restricted_reference',
    'locked_by', 'locked_date', 'locked_reason', 'locked_note', 'locked_reference',
    'id_card_type', 'id_card_num', 'id_card_expiry', 'id_card_issue_date', 'scanned_id',
])]
class Customer extends Model
{
    public const ACCESS_QUICKSTART = 'quickstart';

    public const ACCESS_PENDING = 'pending';

    public const ACCESS_FULL = 'full';

    public const ACCESS_REJECTED = 'rejected';

    protected $connection = 'mysuncash';

    protected $table = 'customers';

    public $timestamps = false;

    public function islandRecord(): BelongsTo
    {
        return $this->belongsTo(Island::class, 'island');
    }

    public function cityRecord(): BelongsTo
    {
        return $this->belongsTo(IslandCity::class, 'city', 'city_id');
    }

    public function ezkardAccount(): BelongsTo
    {
        return $this->belongsTo(EzkardAccount::class, 'ezkard_account_id');
    }

    public function secondaryId(): HasOne
    {
        return $this->hasOne(CustomerSecondaryId::class, 'customer_id');
    }

    public function occupationRecord(): BelongsTo
    {
        return $this->belongsTo(Occupation::class, 'occupation');
    }

    public function employmentPositionLevelRecord(): BelongsTo
    {
        return $this->belongsTo(EmploymentPositionLevel::class, 'employment_position_level');
    }
}
