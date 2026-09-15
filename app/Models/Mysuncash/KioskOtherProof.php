<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Audit trail for every Kiosk Cash Management confirm action (`kiosk_other_proof`). */
#[Fillable(['ref_id', 'store_admin', 'admin_user', 'deposit_status', 'note', 'other_receipt', 'report_receipt', 'created_by', 'updated_by'])]
class KioskOtherProof extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'kiosk_other_proof';

    protected $primaryKey = 'id';

    const CREATED_AT = 'created_date';

    const UPDATED_AT = null;
}
