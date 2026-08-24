<?php

namespace Tests\Feature;

use App\Mail\TwoFactorCodeMail;
use App\Models\User;
use App\Services\TotpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AuthSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_accepts_profile_fields_and_derives_name(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'first_name' => '  Ada ',
            'middle_name' => ' Byron ',
            'last_name' => ' Lovelace ',
            'email' => 'ada@example.test',
            'mobile_number' => ' 09123456789 ',
            'address' => ' London ',
            'password' => 'SecurePassword1!',
            'password_confirmation' => 'SecurePassword1!',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('user.name', 'Ada Byron Lovelace')
            ->assertJsonPath('user.first_name', 'Ada')
            ->assertJsonPath('user.middle_name', 'Byron')
            ->assertJsonPath('user.last_name', 'Lovelace')
            ->assertJsonPath('user.mobile_number', '09123456789')
            ->assertJsonMissingPath('user.password')
            ->assertJsonMissingPath('user.pin')
            ->assertJsonMissingPath('user.two_factor_secret');

        $this->assertDatabaseHas('users', [
            'name' => 'Ada Byron Lovelace',
            'address' => 'London',
        ]);
    }

    public function test_registration_validates_names_email_and_password_confirmation(): void
    {
        $this->postJson('/api/auth/register', [
            'email' => 'not-an-email',
            'password' => 'SecurePassword1!',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['first_name', 'last_name', 'email', 'password']);
    }

    public function test_suspended_users_are_rejected_and_inactive_users_receive_status(): void
    {
        $suspended = User::factory()->create([
            'email' => 'suspended@example.test',
            'password' => 'Password1!',
            'status' => 'suspended',
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $suspended->email,
            'password' => 'Password1!',
        ])->assertForbidden()->assertJsonMissing(['token']);

        $inactive = User::factory()->create([
            'email' => 'inactive@example.test',
            'password' => 'Password1!',
            'status' => 'inactive',
        ]);

        $this->postJson('/api/auth/login', [
            'email' => $inactive->email,
            'password' => 'Password1!',
        ])
            ->assertOk()
            ->assertJsonStructure(['token'])
            ->assertJsonPath('user.status', 'inactive');
    }

    public function test_email_two_factor_setup_and_login_challenge(): void
    {
        Mail::fake();
        $user = User::factory()->create([
            'email' => 'email-2fa@example.test',
            'password' => 'Password1!',
        ]);

        $setup = $this->actingAs($user, 'sanctum')->postJson('/api/auth/2fa/setup', [
            'method' => 'email',
            'current_password' => 'Password1!',
        ])->assertOk()->assertJsonMissing(['secret', 'otpauth_uri']);

        $setupCode = $this->sentCode();
        $this->actingAs($user, 'sanctum')->postJson('/api/auth/2fa/confirm', [
            'challenge' => $setup->json('challenge'),
            'code' => $setupCode,
        ])->assertOk()->assertJsonPath('method', 'email');

        Mail::fake();
        $login = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'Password1!',
        ]);

        $login
            ->assertOk()
            ->assertJsonPath('two_factor_required', true)
            ->assertJsonPath('method', 'email')
            ->assertJsonMissing(['token'])
            ->assertJsonMissing(['secret']);

        $this->postJson('/api/auth/2fa/challenge/verify', [
            'challenge' => $login->json('challenge'),
            'code' => '000000',
        ])->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->postJson('/api/auth/2fa/challenge/verify', [
            'challenge' => $login->json('challenge'),
            'code' => $this->sentCode(),
        ])->assertOk()->assertJsonStructure(['token', 'user']);
    }

    public function test_authenticator_setup_and_login_use_deterministic_totp(): void
    {
        Carbon::setTestNow('2026-08-10 00:00:00');
        $this->assertSame(
            '287082',
            app(TotpService::class)->codeAt('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 59),
        );
        $user = User::factory()->create(['password' => 'Password1!']);

        $setup = $this->actingAs($user, 'sanctum')->postJson('/api/auth/2fa/setup', [
            'method' => 'authenticator',
            'current_password' => 'Password1!',
        ]);

        $setup
            ->assertOk()
            ->assertJsonStructure(['challenge', 'secret', 'otpauth_uri'])
            ->assertJsonPath('method', 'authenticator');

        $secret = $setup->json('secret');
        $code = app(TotpService::class)->codeAt($secret, Carbon::now()->timestamp);

        $this->actingAs($user, 'sanctum')->postJson('/api/auth/2fa/confirm', [
            'challenge' => $setup->json('challenge'),
            'code' => $code,
        ])->assertOk();

        $this->assertNotSame($secret, $user->fresh()->getRawOriginal('two_factor_secret'));
        $this->assertArrayNotHasKey('two_factor_secret', $user->fresh()->toArray());

        $login = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'Password1!',
        ])->assertJsonMissing(['token', 'secret']);

        $this->postJson('/api/auth/2fa/challenge/verify', [
            'challenge' => $login->json('challenge'),
            'code' => $code,
        ])->assertOk()->assertJsonStructure(['token']);

        Carbon::setTestNow();
    }

    public function test_two_factor_setup_requires_password_and_can_be_disabled(): void
    {
        $user = User::factory()->create([
            'password' => 'Password1!',
            'two_factor_method' => 'email',
            'two_factor_enabled_at' => now(),
        ]);

        $this->actingAs($user, 'sanctum')->postJson('/api/auth/2fa/setup', [
            'method' => 'email',
            'current_password' => 'wrong',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->actingAs($user, 'sanctum')->postJson('/api/auth/2fa/disable', [
            'current_password' => 'Password1!',
        ])->assertOk();

        $this->assertNull($user->fresh()->two_factor_method);
    }

    public function test_pin_can_be_set_and_verified_without_exposing_hash(): void
    {
        $user = User::factory()->create(['password' => 'Password1!']);

        $this->actingAs($user, 'sanctum')->putJson('/api/auth/pin', [
            'current_password' => 'wrong',
            'pin' => '1234',
            'pin_confirmation' => '1234',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->actingAs($user, 'sanctum')->putJson('/api/auth/pin', [
            'current_password' => 'Password1!',
            'pin' => '1234',
            'pin_confirmation' => '1234',
        ])->assertOk()->assertJsonPath('has_pin', true);

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check('1234', $fresh->pin));
        $this->assertArrayNotHasKey('pin', $fresh->toArray());
        $this->assertTrue($fresh->has_pin);

        $this->actingAs($user, 'sanctum')->postJson('/api/auth/pin/verify', ['pin' => '9999'])
            ->assertUnprocessable()->assertJsonValidationErrors('pin');
        $this->actingAs($user, 'sanctum')->postJson('/api/auth/pin/verify', ['pin' => '1234'])
            ->assertOk();
    }

    public function test_only_inactive_user_can_delete_account_with_password_and_avatar_cleanup(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('avatars/user.jpg', 'avatar');
        $active = User::factory()->create(['password' => 'Password1!', 'status' => 'active']);

        $this->actingAs($active, 'sanctum')->deleteJson('/api/auth/account', [
            'current_password' => 'Password1!',
        ])->assertUnprocessable()->assertJsonValidationErrors('status');

        $inactive = User::factory()->create([
            'password' => 'Password1!',
            'status' => 'inactive',
            'avatar_path' => 'avatars/user.jpg',
        ]);
        $inactive->createToken('test');

        $this->actingAs($inactive, 'sanctum')->deleteJson('/api/auth/account', [
            'current_password' => 'wrong',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->actingAs($inactive, 'sanctum')->deleteJson('/api/auth/account', [
            'current_password' => 'Password1!',
        ])->assertOk();

        $this->assertDatabaseMissing('users', ['id' => $inactive->id]);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'tokenable_type' => User::class,
            'tokenable_id' => $inactive->id,
        ]);
        Storage::disk('public')->assertMissing('avatars/user.jpg');
    }

    public function test_security_endpoints_require_authentication(): void
    {
        $this->getJson('/api/auth/2fa')->assertUnauthorized();
        $this->putJson('/api/auth/pin', [])->assertUnauthorized();
        $this->deleteJson('/api/auth/account', [])->assertUnauthorized();
    }

    private function sentCode(): string
    {
        $mail = null;
        Mail::assertSent(TwoFactorCodeMail::class, function (TwoFactorCodeMail $message) use (&$mail): bool {
            $mail = $message;

            return true;
        });

        return $mail->code;
    }
}
