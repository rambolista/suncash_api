<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A merchant's physical branch/location. Legacy status: A=active, I=inactive, D=deleted (soft). */
#[Fillable([
    'branch_code', 'description', 'created_by', 'updated_by', 'status',
    'bec_commission', 'wsc_commission', 'btc_commission', 'fc_commission',
    'bec_commission_limit', 'wsc_commission_limit', 'btc_commission_limit', 'fc_commission_limit',
    'ma_commission', 'ma_commission_limit', 'actual_cash', 'client_record_id',
    'address1', 'address2', 'island', 'island_location', 'city', 'state', 'card_pickup_location',
])]
class Branch extends Model
{
    public const STATUS_ACTIVE = 'A';

    public const STATUS_INACTIVE = 'I';

    public const STATUS_DELETED = 'D';

    protected $connection = 'mysuncash';

    protected $table = 'branch';

    public $timestamps = false;

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class, 'client_record_id');
    }

    public function terminals(): HasMany
    {
        return $this->hasMany(Terminal::class, 'branch_id');
    }

    public function posUsers(): HasMany
    {
        return $this->hasMany(MerchantTerminalUser::class, 'branch_id');
    }
}
