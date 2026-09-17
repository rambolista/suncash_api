<?php

namespace App\Services\Notifications\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * WhatsApp via Infobip — reuses the SAME Infobip account/API key as
 * InfobipSmsGateway (same vendor, different channel), not a separate
 * credential set.
 *
 * Business-initiated messages (which this always is — an admin
 * proactively texting a customer, never a live customer-session reply)
 * must use a pre-approved message template, not free text. That's a
 * WhatsApp/Meta policy enforced server-side, not a choice made here.
 * `$message` is passed as the approved template's single body
 * placeholder, so callers keep using the same `send($mobile, $message)`
 * shape as every other gateway.
 *
 * Requires a WhatsApp Business Sender number and an approved template to
 * be set up on the Infobip dashboard first (a manual Meta business-review
 * step, not something this code can do) — `sender`/`template_name`/
 * `language` have no defaults for that reason. Same safety gate as the
 * other gateways: while `services.whatsapp.enabled` is off, `send()` logs
 * and reports "simulated" instead of calling out.
 */
class WhatsAppGateway implements SmsGatewayInterface
{
    public function send(string $mobile, string $message): array
    {
        if (! config('services.whatsapp.enabled')) {
            Log::info('WhatsApp SMS suppressed (disabled in this environment).', [
                'mobile' => $mobile,
                'message' => $message,
            ]);

            return ['sent' => false, 'simulated' => true, 'status' => null, 'gateway' => 'whatsapp', 'request' => null, 'response' => null];
        }

        $url = rtrim((string) config('services.infobip.base_url'), '/').'/whatsapp/1/message/template';
        $response = Http::withHeaders([
            'Authorization' => 'App '.config('services.infobip.api_key'),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->post($url, [
            'messages' => [[
                'from' => config('services.whatsapp.sender'),
                'to' => $mobile,
                'messageId' => (string) Str::uuid(),
                'content' => [
                    'templateName' => config('services.whatsapp.template_name'),
                    'templateData' => [
                        'body' => [
                            'type' => 'POSITIONAL_PARAMETERS',
                            'placeholders' => [$message],
                        ],
                    ],
                    'language' => config('services.whatsapp.language'),
                ],
            ]],
        ]);

        if (! $response->successful()) {
            Log::warning('WhatsApp send failed.', ['mobile' => $mobile, 'status' => $response->status(), 'body' => $response->body()]);
        }

        return [
            'sent' => $response->successful(),
            'simulated' => false,
            'status' => $response->status(),
            'gateway' => 'whatsapp',
            'request' => $url,
            'response' => $response->body(),
        ];
    }
}
