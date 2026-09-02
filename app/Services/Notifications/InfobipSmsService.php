<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Mirrors legacy's `settings::send_sms()` — a real POST to Infobip's
 * Advanced SMS API (`/sms/1/text/advanced`) with a bearer API key and a
 * fixed sender ID. Legacy's own key is hardcoded straight into its source;
 * this reads it from config/env instead so it can be supplied per
 * environment. Deliberately general-purpose (not under `Services\
 * Transactions`) — any feature that needs to text a customer (Resend
 * Transaction Receipt today; anything else later) can inject this directly.
 *
 * Gated behind `services.infobip.enabled` (env `INFOBIP_ENABLED`, default
 * false) so this codebase never fires a real SMS until a deployment
 * deliberately turns it on with real production credentials. While
 * disabled, `send()` logs what would have been sent and reports back as
 * "simulated" rather than silently pretending to succeed.
 */
class InfobipSmsService
{
    public function send(string $mobile, string $message): array
    {
        if (! config('services.infobip.enabled')) {
            Log::info('Infobip SMS suppressed (disabled in this environment).', [
                'mobile' => $mobile,
                'message' => $message,
            ]);

            return ['sent' => false, 'simulated' => true];
        }

        $response = Http::withHeaders([
            'Authorization' => 'App '.config('services.infobip.api_key'),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->post(rtrim((string) config('services.infobip.base_url'), '/').'/sms/1/text/advanced', [
            'messages' => [[
                'from' => config('services.infobip.sender'),
                'destinations' => [['to' => $mobile]],
                'text' => $message,
            ]],
        ]);

        if (! $response->successful()) {
            Log::warning('Infobip SMS send failed.', ['mobile' => $mobile, 'status' => $response->status(), 'body' => $response->body()]);
        }

        return ['sent' => $response->successful(), 'simulated' => false, 'status' => $response->status()];
    }
}
