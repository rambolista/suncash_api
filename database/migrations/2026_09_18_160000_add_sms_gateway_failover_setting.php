<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the "SMS gateway failover" toggle row consumed by
 * SmsManager/SmsGatewaySettingService. Off by default — when off, a failed
 * primary-gateway send is returned as-is; when on, it automatically retries
 * once via the other gateway, but only if that gateway is itself enabled.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::connection('mysuncash')->table('system_settings')->updateOrInsert(
            ['setting_type' => 'sms_gateway_setting', 'set_code' => 'failover_enabled'],
            [
                'name' => 'SMS Gateway Failover',
                'description' => 'When on, if the primary SMS gateway fails to send, the other enabled gateway is tried automatically.',
                'set_value' => 'OFF',
                'is_enable' => 'true',
                'is_active' => 1,
            ]
        );
    }

    public function down(): void
    {
        DB::connection('mysuncash')->table('system_settings')
            ->where('setting_type', 'sms_gateway_setting')
            ->where('set_code', 'failover_enabled')
            ->delete();
    }
};
