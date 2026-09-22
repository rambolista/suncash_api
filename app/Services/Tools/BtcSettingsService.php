<?php

namespace App\Services\Tools;

use App\Models\ActivityLog;
use App\Models\Mysuncash\EmidaSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use SoapClient;
use SoapFault;

/**
 * "Tools > BTC Settings" (legacy `tools/emida_settings` + `emida_model`) —
 * per-channel editable settings (replenish amount, notification email) plus
 * a live terminal balance fetched from Emida's SOAP reseller API.
 *
 * Two channels, matching legacy's dropdown (a third `bahapay` row exists in
 * the DB but has no UI path to it in legacy either — dead data, skipped
 * here). Each channel uses its own Emida terminal credentials.
 *
 * The balance call is gated behind `services.emida.enabled` (default false
 * — see config/services.php), same reasoning as Aliv/ComplyAdvantage: the
 * settings themselves always load/save regardless, only the live balance
 * figure needs real credentials (and the `soap` PHP extension). Every call
 * attempt is logged to `emida_api_logs`, matching legacy's own audit trail.
 */
class BtcSettingsService
{
    public const CHANNELS = [
        'suncash' => 'SunCash',
        'customer_app' => 'SunCash App',
    ];

    private const CODE_REPLENISH = 'replenish_amount';

    private const CODE_EMAIL = 'notification_address';

    private function assertChannel(string $channel): void
    {
        abort_unless(array_key_exists($channel, self::CHANNELS), 422, 'Invalid channel.');
    }

    private function logCall(string $method, array $request, array $response): void
    {
        DB::connection('mysuncash')->table('emida_api_logs')->insert([
            'method' => $method,
            'host' => config('services.emida.wsdl'),
            'request' => json_encode($request),
            'response' => json_encode($response),
            'timestamp' => now(),
            'created_date' => now(),
        ]);
    }

    /** @return array{success: bool, balance?: float, message?: string} */
    private function fetchBalance(string $channel): array
    {
        if (! extension_loaded('soap')) {
            return ['success' => false, 'message' => 'The SOAP PHP extension is not available on this server.'];
        }

        $config = config('services.emida');
        $creds = $channel === 'customer_app'
            ? ['terminalId' => $config['site_id_app'], 'userName' => $config['username_app'], 'password' => $config['password_app']]
            : ['terminalId' => $config['site_id'], 'userName' => $config['username'], 'password' => $config['password']];

        try {
            $client = new SoapClient($config['wsdl']);
            $client->__setLocation($config['servlet']);
            $result = $client->GetTerminalBalance('1', $creds['terminalId'], $creds['userName'], $creds['password']);

            $parsed = json_decode(json_encode(simplexml_load_string($result)), true);
            $this->logCall('GetTerminalBalance', $creds, $parsed ?? []);

            if (($parsed['ResponseCode'] ?? null) != '00') {
                return ['success' => false, 'message' => $parsed['ResponseMessage'] ?? 'Unable to fetch balance.'];
            }

            return ['success' => true, 'balance' => (float) $parsed['Balance']];
        } catch (SoapFault $e) {
            $this->logCall('GetTerminalBalance', $creds, ['error' => $e->getMessage()]);

            return ['success' => false, 'message' => 'Connection to Emida failed.'];
        }
    }

    public function get(string $channel): array
    {
        $this->assertChannel($channel);

        $configured = (bool) config('services.emida.enabled');
        $balance = null;
        $message = null;

        if ($configured) {
            $result = $this->fetchBalance($channel);
            if ($result['success']) {
                $balance = $result['balance'];
            } else {
                $message = $result['message'];
            }
        } else {
            $message = 'Emida API credentials are not configured in this environment — showing saved settings only, live balance could not be fetched.';
        }

        return [
            'channel' => $channel,
            'replenish_amount' => EmidaSetting::where('setting_descr', self::CODE_REPLENISH)->where('channel', $channel)->value('setting_value'),
            'notification_address' => EmidaSetting::where('setting_descr', self::CODE_EMAIL)->where('channel', $channel)->value('setting_value'),
            'balance' => $balance,
            'configured' => $configured,
            'message' => $message,
        ];
    }

    public function update(string $channel, string $amount, string $notificationAddress, User $actor): void
    {
        $this->assertChannel($channel);

        $before = [
            'replenish_amount' => EmidaSetting::where('setting_descr', self::CODE_REPLENISH)->where('channel', $channel)->value('setting_value'),
            'notification_address' => EmidaSetting::where('setting_descr', self::CODE_EMAIL)->where('channel', $channel)->value('setting_value'),
        ];

        $meta = ['updated_date' => now(), 'updated_by' => $actor->name ?? $actor->email];

        EmidaSetting::updateOrCreate(
            ['setting_descr' => self::CODE_REPLENISH, 'channel' => $channel],
            ['setting_value' => $amount] + $meta,
        );
        EmidaSetting::updateOrCreate(
            ['setting_descr' => self::CODE_EMAIL, 'channel' => $channel],
            ['setting_value' => $notificationAddress] + $meta,
        );

        ActivityLog::recordAction(
            $actor,
            'Tools - BTC Settings',
            'updated',
            "Updated BTC settings for {$channel} (replenish amount: {$before['replenish_amount']} -> {$amount}, email: {$before['notification_address']} -> {$notificationAddress})",
        );
    }
}
