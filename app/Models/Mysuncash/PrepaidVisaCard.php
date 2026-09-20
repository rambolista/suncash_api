<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;

/** A customer's separate prepaid Visa card (`prepaid_visa_cards`) — distinct from their main SunCash card (`ezkard_accounts`). */
class PrepaidVisaCard extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'prepaid_visa_cards';

    public $timestamps = false;

    protected $guarded = ['*'];
}
