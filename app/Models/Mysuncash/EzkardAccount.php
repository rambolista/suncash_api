<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A physical/virtual card record (`ezkard_accounts`). Sub Account import
 * only ever creates a placeholder row (card_type_id = -1, zero balance) to
 * satisfy `customers.ezkard_account_id`'s foreign key — mirrors legacy's
 * process_sub_account().
 */
#[Fillable([
    'card_ref_number', 'card_prefix', 'card_number', 'expiry_date', 'cvv_code',
    'card_type_id', 'card_balance', 'mobile_number', 'card_status_id', 'client_id', 'rawcard',
])]
class EzkardAccount extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'ezkard_accounts';

    public $timestamps = false;
}
