<?php

namespace App\Services\Tools;

use App\Models\ActivityLog;
use App\Models\Mysuncash\Customer;
use App\Models\User;
use App\Services\Notifications\Sms\SmsManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * "Tools > Customer Management > Push Notification" (legacy
 * `tools::send_push_notif()` / `callPushNotifApiV2()` — "Upgrade KYC" /
 * "Update App Version"). Legacy has no static FCM server key anywhere —
 * it exchanges a service-account credential for a short-lived OAuth
 * bearer token via a separate script (`api/generate_notif_token.php`,
 * outside both codebases) and caches it in the `push_notif_token` table.
 * This reads that same table directly rather than duplicating the
 * service-account/OAuth flow here, so it stays in sync with whatever
 * legacy's own cron already refreshes. Falls back to SMS when the
 * customer has no FCM token, the cached token is missing, or the push
 * fails. Gated behind `services.fcm.enabled` (default false) — the SMS
 * fallback still works today via the existing `SmsManager`, so this is a
 * real, working action even with FCM left disabled, not just a stub.
 */
class PushNotificationService
{
    public function __construct(private readonly SmsManager $sms) {}

    public const MESSAGES = [
        'kyc' => 'Upload valid Government ID to remove account restrictions.',
        'version' => 'New version available. Please update your app to the latest version.',
    ];

    /** @throws ValidationException */
    public function send(int $customerId, string $type, User $actor): array
    {
        if (! array_key_exists($type, self::MESSAGES)) {
            throw ValidationException::withMessages(['type' => ['Invalid push notification type.']]);
        }

        $customer = Customer::find($customerId);
        if (! $customer) {
            throw ValidationException::withMessages(['id' => ['Customer not found.']]);
        }

        $message = self::MESSAGES[$type];
        $customerToken = DB::connection('mysuncash')->table('customer_firebase_token')->where('customer_id', $customerId)->value('firebase_token');

        $sentViaPush = $customerToken && config('services.fcm.enabled') ? $this->sendFcm($customerToken, $message) : false;

        $sentViaSms = false;
        if (! $sentViaPush) {
            if (blank($customer->mobile)) {
                throw ValidationException::withMessages(['mobile' => ['This customer has no mobile number on file and no push token to fall back from.']]);
            }
            $sentViaSms = $this->sms->send($customer->mobile, $message)['sent'] ?? false;
        }

        $actorName = $actor->name ?? $actor->email;
        ActivityLog::recordAction($actor, 'Tools - Customer Management', 'push_notification_sent', "Sent '{$type}' push notification to customer {$customerId} via ".($sentViaPush ? 'FCM' : 'SMS fallback')." (by {$actorName})");

        return [
            'sent_via' => $sentViaPush ? 'push' : 'sms',
            'message' => $sentViaPush || $sentViaSms
                ? 'Push notification successfully sent.'
                : 'SMS sending is disabled in this environment — no message was actually sent.',
        ];
    }

    /** Legacy `callPushNotifApiV2()` — `SELECT * FROM push_notif_token`, single cached row keeping the OAuth bearer token. */
    private function sendFcm(string $customerToken, string $message): bool
    {
        $bearerToken = DB::connection('mysuncash')->table('push_notif_token')->value('token');
        if (blank($bearerToken)) {
            Log::warning('FCM push notification skipped: no cached push_notif_token available.');

            return false;
        }

        $response = Http::withToken($bearerToken)->post(
            rtrim((string) config('services.fcm.url'), '/'),
            ['message' => ['token' => $customerToken, 'notification' => ['title' => 'SunCash App', 'body' => $message]]]
        );

        if (! $response->successful()) {
            Log::warning('FCM push notification failed.', ['status' => $response->status()]);
        }

        return $response->successful();
    }
}
