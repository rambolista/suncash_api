<?php

namespace App\Services\Notifications\Sms;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SimpleXMLElement;
use Throwable;

/**
 * Mirrors legacy's "aliv" SMS path in `settings::send_sms()` — not a REST
 * API but a SOAP call to a third-party Bahamian carrier gateway
 * (newcomobile.com), used for `1242`-prefixed numbers. Same envelope, same
 * static auth token embedded in the SOAP body (not a header), same success
 * check (`SendSMSResult.Code == '0'`). Unrelated to the "Aliv" mobile-topup
 * carrier code elsewhere in this app (Kiosk billing) — same brand name,
 * different product.
 *
 * Legacy disables SSL verification for this call (`CURLOPT_SSL_VERIFYPEER
 * => 0`) — deliberately not replicated, since skipping certificate
 * validation on a real external endpoint is a security regression, not
 * behavior worth preserving.
 *
 * Gated behind `services.aliv_sms.enabled` (env `ALIV_SMS_ENABLED`, default
 * false), same safety pattern as InfobipSmsGateway.
 */
class AlivSmsGateway implements SmsGatewayInterface
{
    public function send(string $mobile, string $message): array
    {
        if (! config('services.aliv_sms.enabled')) {
            Log::info('Aliv SMS suppressed (disabled in this environment).', [
                'mobile' => $mobile,
                'message' => $message,
            ]);

            return ['sent' => false, 'simulated' => true, 'status' => null, 'gateway' => 'aliv', 'request' => null, 'response' => null];
        }

        $endpoint = (string) config('services.aliv_sms.endpoint');
        $response = Http::withBody($this->buildEnvelope($mobile, $message), 'text/xml;charset="utf-8"')
            ->post($endpoint);

        $code = $this->extractResultCode($response->body());

        if ($code !== '0') {
            Log::warning('Aliv SMS send failed.', ['mobile' => $mobile, 'code' => $code, 'body' => $response->body()]);
        }

        return [
            'sent' => $code === '0',
            'simulated' => false,
            'status' => $code,
            'gateway' => 'aliv',
            'request' => $endpoint,
            'response' => $response->body(),
        ];
    }

    private function buildEnvelope(string $mobile, string $message): string
    {
        $token = htmlspecialchars((string) config('services.aliv_sms.token'), ENT_XML1);
        $destination = htmlspecialchars($mobile, ENT_XML1);
        $text = htmlspecialchars($message, ENT_XML1);

        return <<<XML
        <soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:thel="http://TheListWebService.newcomobile.com/">
            <soapenv:Header/>
            <soapenv:Body>
                <thel:SendSMS>
                    <thel:Token>{$token}</thel:Token>
                    <thel:DestinationAddress>{$destination}</thel:DestinationAddress>
                    <thel:Message>{$text}</thel:Message>
                </thel:SendSMS>
            </soapenv:Body>
        </soapenv:Envelope>
        XML;
    }

    /** Same namespace-prefix-stripping trick legacy uses — the response's SOAP prefix ("soap:") differs from the request's ("soapenv:"). */
    private function extractResultCode(string $responseXml): ?string
    {
        try {
            $stripped = preg_replace('/(<\/?)(\w+):([^>]*>)/', '$1$2$3', $responseXml);
            $xml = new SimpleXMLElement((string) $stripped);
            $body = $xml->xpath('//soapBody')[0] ?? null;
            $decoded = json_decode((string) json_encode($body), true);

            return $decoded['SendSMSResponse']['SendSMSResult']['Code'] ?? null;
        } catch (Throwable) {
            return null;
        }
    }
}
