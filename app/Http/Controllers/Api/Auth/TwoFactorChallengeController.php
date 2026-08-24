<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Services\TotpService;
use App\Services\TwoFactorChallengeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class TwoFactorChallengeController extends Controller
{
    public function __invoke(
        Request $request,
        TwoFactorChallengeService $challenges,
        TotpService $totp,
    ): JsonResponse {
        $data = $request->validate([
            'challenge' => ['required', 'string', 'size:64'],
            'code' => ['required', 'digits:6'],
        ]);

        $challenge = $challenges->find($data['challenge']);

        if (! $challenge || $challenge->purpose !== 'login') {
            throw ValidationException::withMessages(['challenge' => ['The challenge is invalid.']]);
        }

        if ($challenge->expires_at->isPast() || $challenge->attempts >= 5) {
            $challenge->delete();
            throw ValidationException::withMessages(['challenge' => ['The challenge has expired.']]);
        }

        $user = $challenge->user;
        $secret = $user->two_factor_secret;
        $valid = $challenge->method === 'email'
            ? Hash::check($data['code'], (string) $challenge->code_hash)
            : is_string($secret) && $totp->verify($secret, $data['code']);

        if (! $valid) {
            $challenge->increment('attempts');
            throw ValidationException::withMessages(['code' => ['The verification code is invalid.']]);
        }

        if (strtolower((string) $user->status) === 'suspended') {
            $challenge->delete();

            return response()->json(['message' => 'This account is suspended.'], 403);
        }

        $challenge->delete();
        $user->tokens()->delete();
        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => [
                ...$user->loadMissing('roles:id,name')->toArray(),
                'accessible_menu_ids' => $this->getAccessibleMenuIds($user),
                'menu_permissions' => $this->buildMenuPermissionsPayload($user),
            ],
        ]);
    }
}
