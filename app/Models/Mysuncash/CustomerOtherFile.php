<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;

/**
 * Read-only: scanned selfie/card images attached to a credit-card link
 * request, looked up by `reference` = customer_creditcard.id.
 */
class CustomerOtherFile extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'customer_other_files';

    public $timestamps = false;

    protected $guarded = ['*'];
}
