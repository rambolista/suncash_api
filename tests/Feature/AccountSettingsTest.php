<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AccountSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_update_records_only_changed_fields(): void
    {
        $user = User::factory()->create([
            'name' => 'Original Name',
            'mobile_number' => null,
        ]);

        $this
            ->actingAs($user, 'sanctum')
            ->putJson('/api/user/profile', [
                'first_name' => 'Updated',
                'middle_name' => 'Middle',
                'last_name' => 'Name',
                'mobile_number' => '09123456789',
            ])
            ->assertOk()
            ->assertJsonPath('user.name', 'Updated Middle Name');

        $this
            ->actingAs($user, 'sanctum')
            ->getJson('/api/user/profile/history')
            ->assertOk()
            ->assertJsonPath('history.0.changes.first_name.to', 'Updated')
            ->assertJsonPath('history.0.changes.middle_name.to', 'Middle')
            ->assertJsonPath('history.0.changes.last_name.to', 'Name')
            ->assertJsonPath('history.0.changes.mobile_number.from', null)
            ->assertJsonPath('history.0.changes.mobile_number.to', '09123456789');
    }

    public function test_change_password_requires_letters_numbers_and_symbols(): void
    {
        $user = User::factory()->create([
            'password' => 'OldPassword1!',
        ]);

        $this
            ->actingAs($user, 'sanctum')
            ->putJson('/api/auth/change-password', [
                'current_password' => 'OldPassword1!',
                'password' => 'onlyletters',
                'password_confirmation' => 'onlyletters',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $response = $this
            ->actingAs($user, 'sanctum')
            ->putJson('/api/auth/change-password', [
                'current_password' => 'OldPassword1!',
                'password' => 'NewPassword2@',
                'password_confirmation' => 'NewPassword2@',
            ]);

        $response
            ->assertOk()
            ->assertJsonStructure(['message', 'token']);

        $this->assertTrue(Hash::check('NewPassword2@', $user->fresh()->password));
        $this->assertNotNull(PersonalAccessToken::findToken($response->json('token')));
    }

    public function test_unlock_requires_the_current_users_password(): void
    {
        $user = User::factory()->create([
            'password' => 'CurrentPassword1!',
        ]);

        $this
            ->actingAs($user, 'sanctum')
            ->postJson('/api/auth/unlock', ['password' => 'wrong-password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this
            ->actingAs($user, 'sanctum')
            ->postJson('/api/auth/unlock', ['password' => 'CurrentPassword1!'])
            ->assertOk()
            ->assertJsonPath('message', 'Screen unlocked.');
    }
}
