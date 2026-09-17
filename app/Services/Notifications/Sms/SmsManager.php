<?php

namespace App\Services\Notifications\Sms;

use App\Models\Mysuncash\SmsGatewayLog;
use App\Models\Mysuncash\SystemSetting;

/**
 * The single entry point a feature injects to send an SMS — resolves to
 * whichever gateway (Infobip/Aliv/WhatsApp) the admin has picked as
 * primary (Settings > Notifications > SMS Settings), defaulting to
 * Infobip if that setting is missing.
 *
 * Failover is an explicit admin toggle (`failover_enabled`, default OFF —
 * no legacy precedent, new functionality), not automatic-by-default. When
 * on, a REAL failed send on the primary (it was enabled and actually
 * attempted, not just disabled/simulated) retries every OTHER enabled
 * gateway, in `GATEWAYS` order, until one succeeds. When off, or when the
 * primary merely wasn't enabled, a failed/simulated send is returned as-is.
 *
 * Every REAL attempt (any gateway, primary or failover) is logged to
 * `smsgateway_logs` — the same table legacy's own gateways wrote to —
 * so it's ready for a future "SMS Logs" report. Simulated (gateway
 * disabled) attempts are not logged there, only to the app log, since
 * they're not genuine send attempts and this table already holds 45k+
 * rows of real legacy history.
 */
class SmsManager
{
    public const DEFAULT_GATEWAY = 'infobip';

    public const GATEWAYS = [
        'infobip' => InfobipSmsGateway::class,
        'aliv' => AlivSmsGateway::class,
        'whatsapp' => WhatsAppGateway::class,
    ];

    public const CONFIG_KEYS = [
        'infobip' => 'infobip',
        'aliv' => 'aliv_sms',
        'whatsapp' => 'whatsapp',
    ];

    public function send(string $mobile, string $message): array
    {
        $primary = $this->primaryGateway();
        $primaryEnabled = $this->gatewayEnabled($primary);
        $result = $this->attempt(self::GATEWAYS[$primary], $mobile, $message);

        $shouldFailover = $this->failoverEnabled()
            && $primaryEnabled // only a REAL attempt counts as "failed" — not a disabled/simulated one
            && ! $result['sent'];

        if (! $shouldFailover) {
            return $result + ['failed_over' => false];
        }

        foreach (self::GATEWAYS as $gateway => $class) {
            if ($gateway === $primary || ! $this->gatewayEnabled($gateway)) {
                continue;
            }

            $fallback = $this->attempt($class, $mobile, $message);
            if ($fallback['sent']) {
                return $fallback + ['failed_over' => true];
            }
        }

        return $result + ['failed_over' => false];
    }

    /** Calls the gateway and, for any REAL (non-simulated) attempt, logs it to `smsgateway_logs`. */
    private function attempt(string $class, string $mobile, string $message): array
    {
        $result = app($class)->send($mobile, $message);

        if (! $result['simulated']) {
            SmsGatewayLog::create([
                'mobile' => $mobile,
                'status_code' => is_numeric($result['status']) ? (int) $result['status'] : null,
                'message' => $message,
                'response' => $result['response'] ?? null,
                'request' => $result['request'] ?? null,
                'timestamp' => now(),
            ]);
        }

        return $result;
    }

    public function primaryGateway(): string
    {
        $value = $this->settingValue('primary_gateway');

        return array_key_exists($value, self::GATEWAYS) ? $value : self::DEFAULT_GATEWAY;
    }

    public function failoverEnabled(): bool
    {
        return strtoupper((string) $this->settingValue('failover_enabled')) === 'ON';
    }

    private function gatewayEnabled(string $gateway): bool
    {
        return (bool) config('services.'.self::CONFIG_KEYS[$gateway].'.enabled');
    }

    private function settingValue(string $setCode): ?string
    {
        return SystemSetting::where('setting_type', 'sms_gateway_setting')
            ->where('set_code', $setCode)
            ->value('set_value');
    }
}
