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
 * `tools::send_push_notif()` — "Upgrade KYC" / "Update App Version").
 * Legacy calls Firebase Cloud Messaging (a cached OAuth bearer token in
 * `push_notif_token`, refreshed by a process outside this codebase) and
 * falls back to SMS when the customer has no FCM token or the push fails.
 * Gated behind `services.fcm.enabled` (default false, same disabled-by-
 * default pattern as every other unconfigured external integration this
 * session added) — the SMS fallback still works today via the existing
 * `SmsManager`, so this is a real, working action even without FCM
 * credentials, not just a stub.
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
        $token = DB::connection('mysuncash')->table('customer_firebase_token')->where('customer_id', $customerId)->value('firebase_token');

        $sentViaPush = $token && config('services.fcm.enabled') ? $this->sendFcm($token, $message) : false;

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

    private function sendFcm(string $token, string $message): bool
    {
        $response = Http::withToken((string) config('services.fcm.server_key'))->post(
            rtrim((string) config('services.fcm.url'), '/'),
            ['message' => ['token' => $token, 'notification' => ['title' => 'SunCash App', 'body' => $message]]]
        );

        if (! $response->successful()) {
            Log::warning('FCM push notification failed.', ['status' => $response->status()]);
        }

        return $response->successful();
    }
}
