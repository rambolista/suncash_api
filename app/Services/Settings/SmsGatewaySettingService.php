<?php

namespace App\Services\Settings;

use App\Models\ActivityLog;
use App\Models\Mysuncash\SystemSetting;
use App\Models\Mysuncash\WebLog;
use App\Services\Notifications\Sms\SmsManager;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * "Settings > Notifications > SMS Settings" primary-gateway + failover
 * picker — new functionality with no legacy precedent (legacy hardcoded
 * Infobip-vs-Aliv routing by mobile-number country code instead of an
 * admin setting). Stored as `system_settings` rows
 * (`setting_type = 'sms_gateway_setting'`), the same generic keyed-config
 * table `WuSettingService`/notification templates already use —
 * read/written here following that same toggle-with-audit-log pattern.
 */
class SmsGatewaySettingService
{
    private const LABELS = ['infobip' => 'Infobip', 'aliv' => 'Aliv', 'whatsapp' => 'WhatsApp'];

    public function get(): array
    {
        $manager = app(SmsManager::class);

        return [
            'primary' => $manager->primaryGateway(),
            'failover_enabled' => $manager->failoverEnabled(),
            'options' => collect(SmsManager::GATEWAYS)->keys()->map(fn ($value) => [
                'value' => $value,
                'label' => self::LABELS[$value],
                'enabled' => (bool) config('services.'.SmsManager::CONFIG_KEYS[$value].'.enabled'),
            ])->values()->all(),
        ];
    }

    /**
     * @throws ValidationException
     */
    public function updatePrimary(string $primary, string $actorId, string $actorName, string $ipAddress, Request $request): array
    {
        if (! array_key_exists($primary, SmsManager::GATEWAYS)) {
            throw ValidationException::withMessages(['primary' => ['Select a valid SMS gateway.']]);
        }

        $this->saveSetting(
            'primary_gateway',
            'Primary SMS Gateway',
            'Which SMS gateway sends outbound texts (e.g. Resend Transaction Receipt).',
            $primary,
            'Set primary SMS gateway to "'.self::LABELS[$primary].'"',
            $actorId,
            $actorName,
            $ipAddress,
            $request,
        );

        return $this->get();
    }

    public function updateFailover(bool $enabled, string $actorId, string $actorName, string $ipAddress, Request $request): array
    {
        $this->saveSetting(
            'failover_enabled',
            'SMS Gateway Failover',
            'When on, if the primary SMS gateway fails to send, the other enabled gateway is tried automatically.',
            $enabled ? 'ON' : 'OFF',
            ($enabled ? 'Enabled' : 'Disabled').' SMS gateway failover',
            $actorId,
            $actorName,
            $ipAddress,
            $request,
        );

        return $this->get();
    }

    private function saveSetting(
        string $setCode,
        string $name,
        string $description,
        string $value,
        string $activityDescription,
        string $actorId,
        string $actorName,
        string $ipAddress,
        Request $request,
    ): void {
        $setting = SystemSetting::firstOrNew(['setting_type' => 'sms_gateway_setting', 'set_code' => $setCode]);
        $before = $setting->getAttributes();
        $setting->fill([
            'name' => $name,
            'description' => $description,
            'setting_type' => 'sms_gateway_setting',
            'set_code' => $setCode,
            'set_value' => $value,
            'is_active' => 1,
        ])->save();

        WebLog::create([
            'user_id' => $actorId,
            'updated_by' => $actorName,
            'data' => $setCode.' - '.$value,
            'log_type' => 'UPDATE_SMS_GATEWAY_SETTING',
            'user_ip_address' => $ipAddress,
            'cloudflare_ip_address' => $ipAddress,
            'web_channel' => 'admin',
        ]);

        ActivityLog::recordUpdated($request->user(), 'SMS Gateway Setting', $setting, $before, ['set_value'], $request, $activityDescription);
    }
}
