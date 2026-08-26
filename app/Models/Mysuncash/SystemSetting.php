<?php

namespace App\Models\Mysuncash;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Generic keyed config/template table used app-wide: email/SMS
 * notification templates (`setting_type` email|sms), Western Union
 * feature toggles (`setting_type` wu_setting), and other one-off
 * config values, all keyed by a unique `set_code`.
 */
#[Fillable(['name', 'description', 'set_code', 'set_value', 'setting_type', 'tags', 'is_enable', 'subject', 'is_active'])]
class SystemSetting extends Model
{
    protected $connection = 'mysuncash';

    protected $table = 'system_settings';

    public $timestamps = false;
}
