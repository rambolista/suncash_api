<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TwoFactorChallengeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    /**
     * Authenticate a user and issue a Sanctum API token.
     *
     * POST /api/auth/login
     */
    public function __invoke(Request $request, TwoFactorChallengeService $challenges): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (strtolower((string) $user->status) === 'suspended') {
            return response()->json(['message' => 'This account is suspended.'], 403);
        }

        if ($user->two_factor_enabled) {
            return response()->json([
                'two_factor_required' => true,
                'user_status' => $user->status,
                ...$challenges->issue($user, 'login', $user->two_factor_method),
            ]);
        }

        // Revoke all previous tokens so only one active session exists at a time
        $user->tokens()->delete();

        $token = $user->createToken('api-token')->plainTextToken;

        $user->loadMissing('roles');

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
