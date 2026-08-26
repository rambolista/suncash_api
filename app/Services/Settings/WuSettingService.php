<?php

namespace App\Services\Settings;

use App\Models\Mysuncash\Maintenance;
use App\Models\Mysuncash\SystemSetting;
use App\Models\Mysuncash\WebLog;
use Illuminate\Validation\ValidationException;

/**
 * Western Union integration control panel — toggles stored as
 * `system_settings` rows (`setting_type = 'wu_setting'`). Mirrors legacy
 * admin's Settings > SunCash WU screen, including its two special cases:
 * `wu_amount_limit` toggles `is_enable` (its numeric limit lives in
 * `set_value` and isn't edited here), and `wu_notification_outside_bahamas`
 * also mirrors its state into `maintenance.wu_country_validation`. Every
 * toggle is audit-logged into `web_logs`, matching the legacy write.
 *
 * Not carried over: the legacy admin also fires a monitoring e-mail
 * (`send_mail_wu_settings_switch`) on every toggle — deliberately left out
 * here pending the new system's own outbound-mail infrastructure.
 */
class WuSettingService
{
    private const FRIENDLY_NAMES = [
        'IS_WU_ON' => 'Western Union Online Services',
        'IS_WU_RECEIVER_ON' => 'Allow Adding Receivers',
        'wu_amount_limit' => 'Western Union Limit',
        'wu_direct_to_bank' => 'WU Direct to Bank Service',
        'wu_notification_outside_bahamas' => 'WU Notification Outside Bahamas',
        'wu_suspend_trx_us' => 'Automatically Suspend WU Transaction to United States',
        'wu_suspend_trx_jamaica' => 'Automatically Suspend WU Transaction to Jamaica',
    ];

    private const AMOUNT_LIMIT_CODE = 'wu_amount_limit';

    private const OUTSIDE_BAHAMAS_CODE = 'wu_notification_outside_bahamas';

    private const COUNTRY_VALIDATION_CHANNEL = 'wu_country_validation';

    private function isEnabled(SystemSetting $setting): bool
    {
        return $setting->set_code === self::AMOUNT_LIMIT_CODE
            ? in_array(strtolower((string) $setting->is_enable), ['true', '1'], true)
            : strtoupper((string) $setting->set_value) === 'ON';
    }

    public function list(): array
    {
        return SystemSetting::where('setting_type', 'wu_setting')
            ->orderBy('id')
            ->get()
            ->map(fn (SystemSetting $setting) => [
                'id' => $setting->id,
                'label' => self::FRIENDLY_NAMES[$setting->set_code] ?? $setting->name,
                'description' => $setting->description,
                'set_code' => $setting->set_code,
                'value' => $setting->set_value,
                'is_enabled' => $this->isEnabled($setting),
            ])
            ->all();
    }

    /**
     * @throws ValidationException
     */
    public function toggle(int $id, bool $enabled, string $actorId, string $actorName, string $ipAddress): array
    {
        $setting = SystemSetting::where('setting_type', 'wu_setting')->find($id);
        if (! $setting) {
            throw ValidationException::withMessages(['id' => ['WU setting not found.']]);
        }

        if ($setting->set_code === self::AMOUNT_LIMIT_CODE) {
            $setting->update(['is_enable' => $enabled ? 'true' : 'false']);
        } else {
            $setting->update(['set_value' => $enabled ? 'ON' : 'OFF']);

            if ($setting->set_code === self::OUTSIDE_BAHAMAS_CODE) {
                Maintenance::where('channel', self::COUNTRY_VALIDATION_CHANNEL)
                    ->update(['under_maintenance' => $enabled ? 1 : 0]);
            }
        }

        WebLog::create([
            'customer_id' => -1,
            'user_id' => $actorId,
            'updated_by' => $actorName,
            'data' => $setting->set_code.' - '.($enabled ? 'ON' : 'OFF'),
            'log_type' => 'UPDATE_WU_SETTING',
            'user_ip_address' => $ipAddress,
            'web_channel' => 'admin',
        ]);

        return ['id' => $setting->id, 'is_enabled' => $enabled];
    }
}
