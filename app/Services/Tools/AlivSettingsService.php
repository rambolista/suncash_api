<?php

namespace App\Services\Tools;

use App\Models\ActivityLog;
use App\Models\Mysuncash\AlivSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * "Tools > Aliv Settings" (legacy `tools/aliv_settings` + `Aliv_model`) —
 * two editable settings (replenish amount, notification email) plus a
 * live reseller balance fetched from Aliv's mobile-topup API.
 *
 * The balance call is gated behind `services.aliv_topup.enabled` (default
 * false — see config/services.php) the same way ComplyAdvantage is: the
 * settings themselves always load/save regardless, only the live balance
 * figure needs real credentials. Every call attempt is logged to
 * `aliv_logs`, matching legacy's own audit trail.
 *
 * Uses Laravel's HTTP client instead of legacy's raw curl + manual
 * response-header string-splitting to pull the `Authorization` token Aliv
 * returns from login — same two-step login-then-balance flow, just read
 * through `$response->header()` instead of parsing raw header text.
 */
class AlivSettingsService
{
    private const CODE_REPLENISH = 'replenish_balance';

    private const CODE_EMAIL = 'notification_address';

    private function logCall(string $url, array $params, array $response): void
    {
        DB::connection('mysuncash')->table('aliv_logs')->insert([
            'request_params' => json_encode(['url' => $url, 'params' => $params, 'channel' => 'admin']),
            'response' => json_encode($response),
        ]);
    }

    private function login(): ?string
    {
        $config = config('services.aliv_topup');
        $params = [
            'clientContext' => [
                'channel' => 'WSClient',
                'clientId' => '',
                'clientReference' => '',
                'initiatorPrincipalId' => [
                    'id' => $config['reseller_id'],
                    'type' => 'RESELLERUSER',
                    'userId' => $config['user_id'],
                ],
                'password' => $config['password'],
            ],
        ];

        $response = Http::withHeaders(['Content-Type' => 'application/json', 'api-key' => $config['api_key']])
            ->post(rtrim($config['api_url'], '/').'/topupservice/thirdparty/loginlite', $params);

        $this->logCall('topupservice/thirdparty/loginlite', $params, ['status' => $response->status(), 'body' => $response->json()]);

        return $response->successful() ? $response->header('Authorization') : null;
    }

    /** @return array{success: bool, balance?: float, message?: string} */
    private function fetchBalance(): array
    {
        $config = config('services.aliv_topup');
        $auth = $this->login();
        if (! $auth) {
            return ['success' => false, 'message' => 'Unable to authenticate with Aliv.'];
        }

        $params = ['resellerId' => $config['reseller_id']];
        $response = Http::withHeaders(['Content-Type' => 'application/json', 'api-key' => $config['api_key'], 'Authorization' => $auth])
            ->post(rtrim($config['api_url'], '/').'/topupservice/auth/thirdparty/resellerBalance', $params);

        $this->logCall('topupservice/auth/thirdparty/resellerBalance', $params, ['status' => $response->status(), 'body' => $response->json()]);

        if (! $response->successful()) {
            return ['success' => false, 'message' => 'Connection failed!'];
        }

        return ['success' => true, 'balance' => (float) $response->json('balance.value', 0)];
    }

    public function get(): array
    {
        $configured = (bool) config('services.aliv_topup.enabled');
        $balance = null;
        $message = null;

        if ($configured) {
            $result = $this->fetchBalance();
            if ($result['success']) {
                $balance = $result['balance'];
            } else {
                $message = $result['message'];
            }
        } else {
            $message = 'Aliv API credentials are not configured in this environment — showing saved settings only, live balance could not be fetched.';
        }

        return [
            'replenish_balance' => AlivSetting::where('code', self::CODE_REPLENISH)->value('value'),
            'notification_address' => AlivSetting::where('code', self::CODE_EMAIL)->value('value'),
            'balance' => $balance,
            'configured' => $configured,
            'message' => $message,
        ];
    }

    public function update(string $replenishBalance, string $notificationAddress, User $actor): void
    {
        $before = [
            'replenish_balance' => AlivSetting::where('code', self::CODE_REPLENISH)->value('value'),
            'notification_address' => AlivSetting::where('code', self::CODE_EMAIL)->value('value'),
        ];

        AlivSetting::where('code', self::CODE_REPLENISH)->update(['value' => $replenishBalance]);
        AlivSetting::where('code', self::CODE_EMAIL)->update(['value' => $notificationAddress]);

        ActivityLog::recordAction(
            $actor,
            'Tools - Aliv Settings',
            'updated',
            "Updated Aliv settings (replenish amount: {$before['replenish_balance']} -> {$replenishBalance}, email: {$before['notification_address']} -> {$notificationAddress})",
        );
    }
}
