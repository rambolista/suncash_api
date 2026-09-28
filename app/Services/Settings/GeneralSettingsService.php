<?php

namespace App\Services\Settings;

use App\Models\ActivityLog;
use App\Models\Mysuncash\SystemSetting;
use App\Models\Mysuncash\WebLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * "Access Management > General Settings" — currently a single toggle:
 * whether 2FA setup is mandatory for every admin immediately after login.
 * Stored as a `system_settings` row (setting_type = 'security_setting',
 * set_code = 'mandatory_2fa'), enforced by the `require.2fa.setup` middleware.
 */
class GeneralSettingsService
{
    private const SETTING_TYPE = 'security_setting';

    private const MANDATORY_2FA_CODE = 'mandatory_2fa';

    public function mandatoryTwoFactorEnabled(): bool
    {
        $value = SystemSetting::where('setting_type', self::SETTING_TYPE)
            ->where('set_code', self::MANDATORY_2FA_CODE)
            ->value('set_value');

        return strtoupper((string) $value) === 'ON';
    }

    public function status(): array
    {
        return ['mandatory_2fa' => $this->mandatoryTwoFactorEnabled()];
    }

    /** @throws ValidationException */
    public function setMandatoryTwoFactor(bool $enabled, User $actor, Request $request): array
    {
        $setting = SystemSetting::where('setting_type', self::SETTING_TYPE)
            ->where('set_code', self::MANDATORY_2FA_CODE)
            ->first();
        if (! $setting) {
            throw ValidationException::withMessages(['mandatory_2fa' => ['Setting not found.']]);
        }

        $before = $setting->getAttributes();
        $setting->update(['set_value' => $enabled ? 'ON' : 'OFF']);

        WebLog::create([
            'customer_id' => -1,
            'user_id' => $actor->id,
            'updated_by' => $actor->name ?? $actor->email,
            'data' => 'mandatory_2fa - '.($enabled ? 'ON' : 'OFF'),
            'log_type' => 'UPDATE_GENERAL_SETTING',
            'user_ip_address' => $request->ip(),
            'web_channel' => 'admin',
        ]);

        ActivityLog::recordUpdated($actor, 'General Settings', $setting, $before, ['set_value'], $request, ($enabled ? 'Enabled' : 'Disabled').' "Mandatory Two-Factor Authentication"');

        return ['mandatory_2fa' => $enabled];
    }
}
