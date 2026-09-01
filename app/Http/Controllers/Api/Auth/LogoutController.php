<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LogoutController extends Controller
{
    /**
     * Revoke the current user's API token.
     *
     * POST /api/auth/logout
     */
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        ActivityLog::recordAction($user, 'Authentication', 'logged_out', "{$user->name} logged out", $user, $request);

        // Delete only the token used for this request
        $user->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }
}
