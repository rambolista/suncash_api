<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LayoutSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_layout_settings_are_publicly_available_for_authentication_pages(): void
    {
        $this
            ->getJson('/api/layout-settings')
            ->assertOk()
            ->assertJsonPath('settings.theme', 'light');
    }

    public function test_customer_layout_settings_are_publicly_available_for_customer_pages(): void
    {
        $this
            ->getJson('/api/layout-settings?scope=customer')
            ->assertOk()
            ->assertJsonPath('settings.theme', 'light');
    }

    public function test_super_admin_can_persist_sidenav_style(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['super_admin' => true])->save();

        $this
            ->actingAs($user, 'sanctum')
            ->putJson('/api/layout-settings', [
                'sidenavStyle' => 'no-icons-with-lines',
            ])
            ->assertOk()
            ->assertJsonPath('settings.sidenavStyle', 'no-icons-with-lines');

        $this
            ->actingAs($user, 'sanctum')
            ->putJson('/api/layout-settings', [
                'sidenavStyle' => 'unsupported',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sidenavStyle');
    }

    public function test_super_admin_can_persist_customer_theme_settings(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['super_admin' => true])->save();

        $this
            ->actingAs($user, 'sanctum')
            ->putJson('/api/layout-settings?scope=customer', [
                'theme' => 'dark',
            ])
            ->assertOk()
            ->assertJsonPath('settings.theme', 'dark');
    }

    public function test_regular_user_cannot_update_sidenav_style(): void
    {
        $user = User::factory()->create();

        $this
            ->actingAs($user, 'sanctum')
            ->putJson('/api/layout-settings', [
                'sidenavStyle' => 'with-lines',
            ])
            ->assertForbidden();
    }

    public function test_super_admin_can_atomically_sync_global_and_personal_theme(): void
    {
        $user = User::factory()->create([
            'theme_preference' => 'light',
        ]);
        $user->forceFill(['super_admin' => true])->save();

        $this
            ->actingAs($user, 'sanctum')
            ->putJson('/api/layout-settings/theme', ['theme' => 'system'])
            ->assertOk()
            ->assertJsonPath('settings.theme', 'system')
            ->assertJsonPath('theme_preference', 'system');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'theme_preference' => 'system',
        ]);
    }
}
