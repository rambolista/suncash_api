<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A "Customer Benefits Distribution" batch header (`customer_benefits_header`). */
#[Fillable(['batch_name'])]
class CustomerBenefitBatch extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'customer_benefits_header';

    public $timestamps = false;
}
