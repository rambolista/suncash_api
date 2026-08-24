<?php

namespace App\Services;

use App\Mail\TwoFactorCodeMail;
use App\Models\TwoFactorChallenge;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class TwoFactorChallengeService
{
    public function issue(User $user, string $purpose, string $method, ?string $secret = null): array
    {
        $rateLimitKey = "2fa-email:{$purpose}:{$user->id}";
        if ($method === 'email' && RateLimiter::tooManyAttempts($rateLimitKey, 3)) {
            throw new TooManyRequestsHttpException(
                RateLimiter::availableIn($rateLimitKey),
                'Too many codes requested. Please try again later.',
            );
        }

        $user->twoFactorChallenges()->where('purpose', $purpose)->delete();

        $plainToken = Str::random(64);
        $code = $method === 'email'
            ? str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT)
            : null;

        TwoFactorChallenge::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plainToken),
            'purpose' => $purpose,
            'method' => $method,
            'code_hash' => $code === null ? null : Hash::make($code),
            'secret' => $secret,
            'expires_at' => now()->addMinutes(5),
        ]);

        if ($code !== null) {
            RateLimiter::hit($rateLimitKey, 60);
            Mail::to($user->email)->send(new TwoFactorCodeMail($code));
        }

        return [
            'challenge' => $plainToken,
            'method' => $method,
            'expires_in' => 300,
        ];
    }

    public function find(string $plainToken): ?TwoFactorChallenge
    {
        return TwoFactorChallenge::query()
            ->with('user')
            ->where('token_hash', hash('sha256', $plainToken))
            ->first();
    }
}
