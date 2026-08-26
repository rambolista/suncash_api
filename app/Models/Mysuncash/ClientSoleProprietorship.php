<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;

/** Business/Charity entity-type lookup (Partnership, Corporation, Church, Charity, etc.) — read-only reference data. */
class ClientSoleProprietorship extends Model
{
    /** Ids offered on Business's Initial Info screen. */
    public const BUSINESS_IDS = [1, 4, 5, 9];

    /** Ids offered on Charity's Initial Info screen. */
    public const CHARITY_IDS = [2, 3];

    protected $connection = 'mysuncash';

    protected $table = 'client_sole_proprietorship';

    public $timestamps = false;
}
