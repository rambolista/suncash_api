<?php

namespace App\Services\Tools;

use App\Models\ActivityLog;
use App\Models\Mysuncash\EzkardAccount;
use App\Models\User;
use App\Services\Notifications\Sms\SmsManager;
use Illuminate\Validation\ValidationException;

/**
 * "Tools > Send SMS" (legacy `tools/sms_to_cardholders` +
 * `tools_model::send_sms_to_cardholders()`). A specific mobile number sends
 * to that one recipient; a blank number broadcasts to every activated card
 * (`ezkard_accounts.card_status_id = 0`) — same targeting legacy used.
 * Sends synchronously in a loop, same as legacy (no queue infrastructure
 * exists in this codebase yet); `SmsManager` already logs every real
 * attempt to `smsgateway_logs`, so unlike legacy this doesn't need its own
 * separate `ezkard_accounts_sms` log table.
 */
class SendSmsService
{
    public function __construct(private readonly SmsManager $sms) {}

    public function recipientCount(): int
    {
        return EzkardAccount::where('card_status_id', 0)->whereNotNull('mobile_number')->where('mobile_number', '!=', '')->count();
    }

    /** @throws ValidationException */
    public function send(?string $mobile, string $message, User $actor): array
    {
        $mobile = trim((string) $mobile);
        $numbers = $mobile !== '' ? collect([$mobile]) : $this->activatedCardNumbers();

        if ($numbers->isEmpty()) {
            throw ValidationException::withMessages(['mobile_number' => ['No activated cardholders found to send to.']]);
        }

        set_time_limit(0);

        $sent = $simulated = 0;
        $gateway = null;
        foreach ($numbers as $number) {
            $result = $this->sms->send($number, $message);
            $gateway ??= $result['gateway'] ?? null;
            if ($result['sent']) {
                $sent++;
            }
            if ($result['simulated']) {
                $simulated++;
            }
        }

        $total = $numbers->count();
        $description = $mobile !== ''
            ? "Sent SMS to {$mobile}"
            : "Broadcast SMS to all activated cardholders ({$sent}/{$total} succeeded)";
        ActivityLog::recordAction($actor, 'Tools - Send SMS', 'sent', $description);

        return [
            'total' => $total,
            'sent' => $sent,
            'failed' => $total - $sent,
            'simulated' => $simulated === $total,
            'gateway' => $gateway,
        ];
    }

    private function activatedCardNumbers()
    {
        return EzkardAccount::where('card_status_id', 0)->whereNotNull('mobile_number')->where('mobile_number', '!=', '')->pluck('mobile_number');
    }
}
