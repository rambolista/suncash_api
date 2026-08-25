<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Per-merchant ON/OFF toggle for the store float feature. */
#[Fillable(['merchant_id', 'status', 'create_by', 'update_by'])]
class CashierStoreFloatSetting extends Model
{
    public const ENABLED = 'ENABLED';

    public const DISABLED = 'DISABLED';

    protected $connection = 'mysuncash';

    protected $table = 'cashier_store_float_settings';

    public $timestamps = false;

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class, 'merchant_id');
    }
}
