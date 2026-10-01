<?php

namespace App\Services\Tools;

use App\Models\ActivityLog;
use App\Models\Mysuncash\Merchant;
use App\Models\Mysuncash\SandDollarAuth;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "Tools > Sanddollar Activation" (legacy `tools/sanddollar_list` +
 * `tools/sanddollar_activation_form` + `sanddollar_model`). Pairs a
 * merchant's Sand Dollar mobile wallet "card-less" device via SandDollar's
 * pair-cardless API, then stores the paired device in `sand_dollar_auth`.
 * Legacy only supports merchant accounts through this form (a customer
 * toggle was started but never wired up — `is_client_merchant` is hardcoded
 * to 1 in the controller), so this mirrors that scope rather than the
 * unfinished ambition.
 */
class SandDollarActivationService
{
    private function present(SandDollarAuth $auth): array
    {
        return [
            'id' => $auth->id,
            'account_name' => $auth->merchant?->dba_name ?: $auth->merchant?->legal_name,
            'device_no' => $auth->device_no,
            'type' => $auth->type,
            'status' => (int) $auth->status === SandDollarAuth::STATUS_ACTIVE ? 'ACTIVE' : 'INACTIVE',
            'created_date' => $auth->timestamp,
        ];
    }

    /** Legacy's getClientInfo() — every client, unfiltered. */
    public function merchants(): array
    {
        return Merchant::orderBy('dba_name')
            ->get(['id', 'dba_name', 'legal_name'])
            ->map(fn (Merchant $m) => ['id' => $m->id, 'name' => $m->dba_name ?: $m->legal_name])
            ->all();
    }

    public function list(): array
    {
        return SandDollarAuth::with('merchant')
            ->orderByDesc('id')
            ->get()
            ->map(fn (SandDollarAuth $auth) => $this->present($auth))
            ->all();
    }

    private function validate(array $data): void
    {
        if (! filled($data['merchant_id'] ?? null)) {
            throw ValidationException::withMessages(['merchant_id' => ['Merchant cannot be empty.']]);
        }
        if (! filled($data['custom_name'] ?? null)) {
            throw ValidationException::withMessages(['custom_name' => ['Custom name cannot be empty.']]);
        }
        if (! filled($data['activation_code'] ?? null)) {
            throw ValidationException::withMessages(['activation_code' => ['Activation code cannot be empty.']]);
        }
    }

    /**
     * Legacy's sanddollar_model::pairDeviceCardLess(). Outside a real,
     * explicitly-enabled SandDollar integration this returns the exact same
     * fixed fake pairing data legacy's own non-prod environments return.
     *
     * @throws ValidationException
     */
    private function pairDeviceCardless(string $activationCode, string $customName): array
    {
        if (! config('services.sand_dollar.enabled')) {
            return [
                'device_id' => 'c94cecec-adb4-4d4c-8231-08e79071e385',
                'device_no' => 'TFCMCR38RZHB',
                'auth_secret' => 'eQzJFQgp6JayK6bZybOeLxiq0Lp1qHSQPq0fMxuN8Mw=',
                'can_setup_customname' => '1',
                'is_receive_only' => '0',
            ];
        }

        $publicKey = (string) config('services.sand_dollar.public_key');
        $privateKey = (string) config('services.sand_dollar.private_key');

        $response = Http::asJson()->post(config('services.sand_dollar.endpoint').'/devices/pair-cardless', [
            'activationCode' => $activationCode,
            'model' => $customName,
            'publicKey' => $publicKey,
        ])->json();

        if (! isset($response['authSecret'])) {
            throw ValidationException::withMessages(['activation_code' => [$response['ResponseMessage'] ?? 'Unable to activate sanddollar account.']]);
        }

        $decrypt = function (string $value) use ($privateKey) {
            openssl_private_decrypt(base64_decode($value), $decrypted, $privateKey);

            return $decrypted;
        };

        return [
            'device_id' => $decrypt($response['deviceId']),
            'device_no' => $decrypt($response['deviceNo']),
            'auth_secret' => $decrypt($response['authSecret']),
            'can_setup_customname' => (string) $response['canSetupCustomName'],
            'is_receive_only' => (string) $response['isReceiveOnly'],
        ];
    }

    /** @throws ValidationException */
    public function activate(array $data, User $actor, ?Request $request = null): array
    {
        $this->validate($data);

        if (SandDollarAuth::where('client_id', $data['merchant_id'])->exists()) {
            throw ValidationException::withMessages(['merchant_id' => ['Sorry, but this pooled wallet is already linked.']]);
        }

        $paired = $this->pairDeviceCardless($data['activation_code'], Str::of($data['custom_name'])->trim()->toString());

        $auth = SandDollarAuth::create([
            'is_client_merchant' => 1,
            'client_id' => $data['merchant_id'],
            'device_id' => $paired['device_id'],
            'device_no' => $paired['device_no'],
            'auth_secret' => $paired['auth_secret'],
            'can_setup_customname' => $paired['can_setup_customname'],
            'is_receive_only' => $paired['is_receive_only'],
            // Legacy's method is named "CardLess" and calls the pair-cardless
            // endpoint, but its own insert always stores type "card" — kept
            // as-is to match existing production data/reporting that filters
            // on this column.
            'type' => 'card',
            'custom_name' => trim($data['custom_name']),
            'status' => SandDollarAuth::STATUS_ACTIVE,
        ]);

        ActivityLog::recordCreated($actor, 'Tools - Sanddollar Activation', $auth, ['client_id', 'device_no', 'custom_name', 'type'], $request);

        return $this->present($auth->load('merchant'));
    }
}
