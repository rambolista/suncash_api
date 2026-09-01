<?php

namespace App\Services\Settings;

use App\Models\ActivityLog;
use App\Models\Mysuncash\Maintenance;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Customer mobile-app fraud/compliance feature-flag panel — a fixed set
 * of named toggles in the generic `maintenance` table that gate anti-fraud
 * restrictions enforced by the customer-facing services API. There is no
 * add/remove here, only these predefined channels (mirrors legacy admin's
 * Settings > SunCash Customer App screen).
 */
class CustomerAppSettingService
{
    private const CHANNELS = [
        'off_international_signup' => 'Block International Signup',
        'is_sms_blocked_jamaica' => 'Block SMS To Jamaica',
        'temporary_account_restriction_for_new_device' => 'New Device Temporary Restriction',
        'block_international_device_linking' => 'Block International Device Linking',
        'load_card_sd' => 'Load Card via SandDollar',
        'p2p_sd' => 'P2P via SandDollar',
        'lock_impossible_travel' => 'Lock Impossible Travel',
    ];

    public function list(): array
    {
        $rows = Maintenance::whereIn('channel', array_keys(self::CHANNELS))->get()->keyBy('channel');

        return collect(self::CHANNELS)
            ->map(function (string $label, string $channel) use ($rows) {
                $row = $rows->get($channel);

                return [
                    'id' => $row?->id,
                    'channel' => $channel,
                    'label' => $label,
                    'description' => $row?->msg,
                    'is_enabled' => (bool) ($row?->under_maintenance),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @throws ValidationException
     */
    public function toggle(int $id, bool $enabled, Request $request): array
    {
        $row = Maintenance::whereIn('channel', array_keys(self::CHANNELS))->find($id);
        if (! $row) {
            throw ValidationException::withMessages(['id' => ['Setting not found.']]);
        }

        $before = $row->getAttributes();
        $row->update(['under_maintenance' => $enabled ? 1 : 0]);

        $label = self::CHANNELS[$row->channel] ?? $row->channel;
        ActivityLog::recordUpdated($request->user(), 'Customer App Settings', $row, $before, ['under_maintenance'], $request, ($enabled ? 'Enabled' : 'Disabled')." \"{$label}\"");

        return ['id' => $row->id, 'is_enabled' => $enabled];
    }
}
