<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Model;

/** Uploaded KYC/registration documents for a Charity's Initial Info screen — read-only here (no upload flow in this build). */
class ClientDocument extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'client_documents';

    public $timestamps = false;
}
