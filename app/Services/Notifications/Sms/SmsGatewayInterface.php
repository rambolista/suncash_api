<?php

namespace App\Services\Notifications\Sms;

/** A pluggable outbound-SMS provider (Infobip, Aliv, ...) — see SmsManager for gateway selection. */
interface SmsGatewayInterface
{
    /**
     * @return array{sent: bool, simulated: bool, status: string|int|null, gateway: string, request: string|null, response: string|null}
     */
    public function send(string $mobile, string $message): array;
}
