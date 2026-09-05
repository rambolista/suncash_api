<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Global catalog of kiosk products/services (`kiosk_modules`). A terminal enables a subset via `kiosk_terminal.access_modules`. */
#[Fillable(['status'])]
class KioskModule extends Model
{
    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_INACTIVE = 'INACTIVE';

    protected $connection = 'mysuncash';

    protected $table = 'kiosk_modules';

    public $timestamps = false;
}
