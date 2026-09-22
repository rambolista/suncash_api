<?php

namespace App\Services\Tools;

use App\Models\ActivityLog;
use App\Models\Mysuncash\PrepaySetting;
use App\Models\User;

/**
 * "Tools > Prepaynation Settings" (legacy `tools/prepay_settings`) — a
 * single-channel (`suncash` only, matching legacy's hardcoding) pair of
 * editable settings: replenish threshold and notification email.
 *
 * Legacy has no live balance call anywhere on this screen — its "Current
 * Balance" field just redisplays the stored threshold under a misleading
 * label, and the editable amount field never pre-fills with the real saved
 * value (always resets to a hardcoded 100/200 default), so resubmitting
 * without changing it silently overwrites the real threshold. Not
 * replicated here: this just shows/saves the real current value, like every
 * other settings screen in this app.
 */
class PrepaySettingsService
{
    private const CHANNEL = 'suncash';

    private const CODE_REPLENISH = 'replenish_balance';

    private const CODE_EMAIL = 'notification_address';

    public function get(): array
    {
        return [
            'replenish_balance' => PrepaySetting::where('setting_descr', self::CODE_REPLENISH)->where('channel', self::CHANNEL)->value('setting_value'),
            'notification_address' => PrepaySetting::where('setting_descr', self::CODE_EMAIL)->where('channel', self::CHANNEL)->value('setting_value'),
        ];
    }

    public function update(string $amount, string $notificationAddress, User $actor): void
    {
        $before = $this->get();

        $meta = ['updated_date' => now(), 'updated_by' => $actor->name ?? $actor->email];

        PrepaySetting::updateOrCreate(
            ['setting_descr' => self::CODE_REPLENISH, 'channel' => self::CHANNEL],
            ['setting_value' => $amount] + $meta,
        );
        PrepaySetting::updateOrCreate(
            ['setting_descr' => self::CODE_EMAIL, 'channel' => self::CHANNEL],
            ['setting_value' => $notificationAddress] + $meta,
        );

        ActivityLog::recordAction(
            $actor,
            'Tools - Prepaynation Settings',
            'updated',
            "Updated Prepaynation settings (replenish amount: {$before['replenish_balance']} -> {$amount}, email: {$before['notification_address']} -> {$notificationAddress})",
        );
    }
}
