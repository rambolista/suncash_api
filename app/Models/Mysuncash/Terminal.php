<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A merchant's registered device (terminal/POS/mobile/kiosk/ATM/PC). */
#[Fillable([
    'client_id', 'branch_id', 'device_id', 'device_alias', 'device_status_id',
    'user_id_create', 'user_id_modify', 'creation_date', 'modification_date',
    'device_type_id', 'brand_name', 'model', 'connection_type_id',
    'lane_counter', 'counter_no', 'serial_key', 'serial_key_status',
])]
class Terminal extends Model
{
    public const STATUSES = [0 => 'active', 1 => 'inactive', 2 => 'deactive'];

    public const DEVICE_TYPES = [1 => 'Terminal', 2 => 'POS', 3 => 'Mobile', 4 => 'Kiosk', 5 => 'ATM', 6 => 'PC'];

    public const CONNECTION_TYPES = [1 => 'Dial-up', 2 => 'Wireless', 3 => 'Wifi', 4 => 'Lan-IP'];

    protected $connection = 'mysuncash';

    protected $table = 'terminals';

    public $timestamps = false;

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class, 'client_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }
}
