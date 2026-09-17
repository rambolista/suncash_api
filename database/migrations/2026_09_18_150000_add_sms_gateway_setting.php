<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the "primary SMS gateway" setting row consumed by
 * SmsManager/SmsGatewaySettingService — new functionality with no legacy
 * precedent (legacy hardcoded Infobip-vs-Aliv routing by mobile country
 * code instead of an admin setting). Defaults to Infobip, the
 * already-working modern integration. Lives in the shared `mysuncash`
 * `system_settings` table, the same generic keyed-config table
 * WuSettingService/notification templates already use — purely additive
 * and inert from legacy's own perspective (legacy never reads this
 * set_code).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::connection('mysuncash')->table('system_settings')->updateOrInsert(
            ['setting_type' => 'sms_gateway_setting', 'set_code' => 'primary_gateway'],
            [
                'name' => 'Primary SMS Gateway',
                'description' => 'Which SMS gateway sends outbound texts (e.g. Resend Transaction Receipt).',
                'set_value' => 'infobip',
                'is_enable' => 'true',
                'is_active' => 1,
            ]
        );
    }

    public function down(): void
    {
        DB::connection('mysuncash')->table('system_settings')
            ->where('setting_type', 'sms_gateway_setting')
            ->where('set_code', 'primary_gateway')
            ->delete();
    }
};
