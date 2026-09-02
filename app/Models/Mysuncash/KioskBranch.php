<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A kiosk/ATM location grouping (`kiosk_branch`) — several `kiosk_terminal` rows belong to one branch. */
#[Fillable(['code', 'name', 'address', 'city', 'state', 'zip', 'client_id', 'create_by', 'create_date', 'status', 'update_by', 'update_date'])]
class KioskBranch extends Model
{
    public const STATUS_ACTIVE = 'A';

    public const STATUS_DELETED = 'D';

    protected $connection = 'mysuncash';

    protected $table = 'kiosk_branch';

    public $timestamps = false;

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class, 'client_id');
    }

    public function terminals(): HasMany
    {
        return $this->hasMany(KioskTerminal::class, 'kiosk_branch_id');
    }

    public function partners(): HasMany
    {
        return $this->hasMany(KioskThirdPartyUser::class, 'kiosk_branch_id');
    }
}
