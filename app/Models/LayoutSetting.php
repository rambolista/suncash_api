<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LayoutSetting extends Model
{
    protected $fillable = ['scope', 'settings', 'updated_by'];

    protected $casts = [
        'settings' => 'array',
        'updated_by' => 'integer',
    ];
}
