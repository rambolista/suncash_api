<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Settings\GeneralSettingsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side enforcement of "Access Management > General Settings >
 * Mandatory Two-Factor Authentication". The frontend redirects a user
 * without 2FA straight to the setup page after login, but this is what
 * actually stops every other endpoint from working if they try to bypass
 * that redirect (e.g. typing a dashboard URL directly). The setup flow's
 * own routes (auth/2fa/*, auth/logout, auth/user) are excluded via
 * ->withoutMiddleware() in routes/api.php so the user can still reach them.
 */
class RequireTwoFactorSetup
{
    public function __construct(private readonly GeneralSettingsService $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->two_factor_enabled || ! $this->settings->mandatoryTwoFactorEnabled()) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Two-factor authentication setup is required before continuing.',
            'two_factor_setup_required' => true,
        ], 403);
    }
}
