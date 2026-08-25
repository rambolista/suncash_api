<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'password'])]
#[Hidden(['password'])]
class PasswordHistory extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'password_history';

    public $timestamps = false;

    public function userAccount(): BelongsTo
    {
        return $this->belongsTo(UserAccount::class, 'user_id');
    }
}
