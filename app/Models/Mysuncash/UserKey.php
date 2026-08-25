<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Holds the per-user random key half of the AES key used to encrypt user_account.password (see LegacyCredentialCipher). */
#[Fillable(['user_id', 'key', 'channel'])]
#[Hidden(['key'])]
class UserKey extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'user_keys';

    public $timestamps = false;

    public function userAccount(): BelongsTo
    {
        return $this->belongsTo(UserAccount::class, 'user_id');
    }
}
