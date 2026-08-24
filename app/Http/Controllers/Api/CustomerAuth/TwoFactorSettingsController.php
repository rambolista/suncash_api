<?php

namespace App\Http\Controllers\Api\CustomerAuth;

use App\Http\Controllers\Controller;
use App\Services\CustomerTwoFactorChallengeService;
use App\Services\TotpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TwoFactorSettingsController extends Controller
{
    public function status(Request $request): JsonResponse
    {
        return response()->json([
            'enabled' => $request->user()->two_factor_enabled,
            'method' => $request->user()->two_factor_method,
            'enabled_at' => $request->user()->two_factor_enabled_at,
        ]);
    }

    public function setup(
        Request $request,
        CustomerTwoFactorChallengeService $challenges,
        TotpService $totp,
    ): JsonResponse {
        $data = $request->validate([
            'method' => ['required', Rule::in(['email', 'authenticator'])],
            'current_password' => ['required', 'string'],
        ]);

        $this->ensureCurrentPassword($request, $data['current_password']);

        $secret = $data['method'] === 'authenticator' ? $totp->generateSecret() : null;
        $payload = $challenges->issue($request->user(), 'setup', $data['method'], $secret);

        if ($secret !== null) {
            $payload['secret'] = $secret;
            $payload['otpauth_uri'] = $totp->uri($secret, $request->user()->email);
        }

        return response()->json($payload);
    }

    public function confirm(
        Request $request,
        CustomerTwoFactorChallengeService $challenges,
        TotpService $totp,
    ): JsonResponse {
        $data = $request->validate([
            'challenge' => ['required', 'string', 'size:64'],
            'code' => ['required', 'digits:6'],
        ]);

        $challenge = $challenges->find($data['challenge']);

        if (! $challenge || $challenge->purpose !== 'setup' || $challenge->customer_id !== $request->user()->id) {
            throw ValidationException::withMessages(['challenge' => ['The challenge is invalid.']]);
        }

        $this->assertUsableAndVerify($challenge, $data['code'], $totp);

        $request->user()->forceFill([
            'two_factor_method' => $challenge->method,
            'two_factor_secret' => $challenge->method === 'authenticator' ? $challenge->secret : null,
            'two_factor_enabled_at' => now(),
        ])->save();

        $challenge->delete();

        return response()->json([
            'message' => 'Two-factor authentication enabled.',
            'method' => $request->user()->two_factor_method,
        ]);
    }

    public function disable(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
        ]);

        $this->ensureCurrentPassword($request, $data['current_password']);

        $request->user()->twoFactorChallenges()->delete();
        $request->user()->forceFill([
            'two_factor_method' => null,
            'two_factor_secret' => null,
            'two_factor_enabled_at' => null,
        ])->save();

        return response()->json(['message' => 'Two-factor authentication disabled.']);
    }

    private function ensureCurrentPassword(Request $request, string $password): void
    {
        if (! Hash::check($password, $request->user()->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }
    }

    private function assertUsableAndVerify($challenge, string $code, TotpService $totp): void
    {
        if ($challenge->expires_at->isPast() || $challenge->attempts >= 5) {
            $challenge->delete();
            throw ValidationException::withMessages(['challenge' => ['The challenge has expired.']]);
        }

        $valid = $challenge->method === 'email'
            ? Hash::check($code, (string) $challenge->code_hash)
            : $totp->verify((string) $challenge->secret, $code);

        if (! $valid) {
            $challenge->increment('attempts');
            throw ValidationException::withMessages(['code' => ['The verification code is invalid.']]);
        }
    }
}
