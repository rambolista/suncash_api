<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_sends_a_frontend_reset_link(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this
            ->postJson('/api/auth/forgot-password', ['email' => $user->email])
            ->assertOk();

        Notification::assertSentTo(
            $user,
            ResetPassword::class,
            function (ResetPassword $notification) use ($user): bool {
                $url = $notification->toMail($user)->actionUrl;

                return str_starts_with($url, config('app.frontend_url').'/auth/new-pass?')
                    && str_contains($url, 'token=')
                    && str_contains($url, 'email='.rawurlencode($user->email));
            }
        );
    }
}
