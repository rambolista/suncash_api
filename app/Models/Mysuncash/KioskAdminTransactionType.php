<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;

/**
 * Lookup values for the Kiosk Deposits and Adjustments modal
 * (`kiosk_admin_transaction_types`) — `action` groups rows into the
 * Debit reason list, Credit reason list, Deposit Location list, and
 * Non-Recycled Bill Destination list.
 */
class KioskAdminTransactionType extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'kiosk_admin_transaction_types';

    public $timestamps = false;
}
