<?php

namespace App\Services\Settings;

use App\Models\ActivityLog;
use App\Models\Mysuncash\SystemSetting;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The transactional email/SMS template manager — every outbound system
 * e-mail or SMS (receipts, OTPs, alerts, etc.) has a row here keyed by
 * `set_code`, with a `{tag}`-templated body and a per-row enable switch.
 * Mirrors legacy admin's Settings > Notifications screen.
 */
class NotificationSettingService
{
    private const TYPES = ['email', 'sms'];

    private function isEnabled(SystemSetting $setting): bool
    {
        return in_array(strtolower((string) $setting->is_enable), ['true', '1'], true);
    }

    private function findOrFail(int $id): SystemSetting
    {
        $setting = SystemSetting::whereIn('setting_type', self::TYPES)->find($id);
        if (! $setting) {
            throw ValidationException::withMessages(['id' => ['Notification setting not found.']]);
        }

        return $setting;
    }

    public function listByType(string $type): array
    {
        if (! in_array($type, self::TYPES, true)) {
            throw ValidationException::withMessages(['type' => ['Invalid notification type.']]);
        }

        return SystemSetting::where('setting_type', $type)
            ->where('is_active', '>=', 1)
            ->orderBy('name')
            ->get()
            ->map(fn (SystemSetting $setting) => [
                'id' => $setting->id,
                'name' => $setting->name,
                'description' => $setting->description,
                'set_code' => $setting->set_code,
                'subject' => $setting->subject,
                'is_enabled' => $this->isEnabled($setting),
            ])
            ->all();
    }

    public function find(int $id): array
    {
        $setting = $this->findOrFail($id);

        return [
            'id' => $setting->id,
            'name' => $setting->name,
            'description' => $setting->description,
            'set_code' => $setting->set_code,
            'setting_type' => $setting->setting_type,
            'set_value' => $setting->set_value,
            'subject' => $setting->subject,
            'tags' => $setting->tags,
            'is_enabled' => $this->isEnabled($setting),
        ];
    }

    /**
     * @throws ValidationException
     */
    public function update(int $id, array $data, Request $request): array
    {
        $setting = $this->findOrFail($id);

        $setValue = trim((string) ($data['set_value'] ?? ''));
        $subject = trim((string) ($data['subject'] ?? ''));

        $errors = [];
        if ($setValue === '') {
            $errors['set_value'] = ['The message body is required.'];
        }
        if ($setting->setting_type === 'email' && $subject === '') {
            $errors['subject'] = ['Subject is required for e-mail notifications.'];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        if ($setting->setting_type === 'sms') {
            $setValue = html_entity_decode(strip_tags($setValue));
        }

        $before = $setting->getAttributes();
        $setting->update(['set_value' => $setValue, 'subject' => $subject ?: null]);

        ActivityLog::recordUpdated($request->user(), 'Notification Settings', $setting, $before, ['set_value', 'subject'], $request);

        return $this->find($id);
    }

    /**
     * @throws ValidationException
     */
    public function toggle(int $id, bool $enabled, Request $request): array
    {
        $setting = $this->findOrFail($id);

        $before = $setting->getAttributes();
        $setting->update(['is_enable' => $enabled ? 'true' : 'false']);

        ActivityLog::recordUpdated($request->user(), 'Notification Settings', $setting, $before, ['is_enable'], $request, ($enabled ? 'Enabled' : 'Disabled')." notification \"{$setting->name}\"");

        return ['id' => $setting->id, 'is_enabled' => $enabled];
    }
}
