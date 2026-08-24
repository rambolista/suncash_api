<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProjectSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_settings_are_publicly_readable(): void
    {
        $this
            ->getJson('/api/project-settings')
            ->assertOk()
            ->assertJsonPath('settings.name', 'AdminStarterKit')
            ->assertJsonPath('settings.authentication_type', 'basic')
            ->assertJsonPath('settings.customer_authentication_type', 'basic');
    }

    public function test_regular_user_cannot_update_project_settings(): void
    {
        $user = User::factory()->create();

        $this
            ->actingAs($user, 'sanctum')
            ->putJson('/api/project-settings', [
                'name' => 'Blocked',
                'author' => 'Blocked',
                'description' => null,
                'authentication_type' => 'card',
                'customer_authentication_type' => 'basic',
            ])
            ->assertForbidden();
    }

    public function test_super_admin_can_update_project_settings(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['super_admin' => true])->save();

        $this
            ->actingAs($user, 'sanctum')
            ->putJson('/api/project-settings', [
                'name' => 'Example Project',
                'author' => 'Example Author',
                'year' => 2030,
                'description' => 'Example description.',
                'authentication_type' => 'split',
                'customer_authentication_type' => 'card',
                'sidenav_gradient_start' => '#112233',
                'sidenav_gradient_end' => '#445566',
                'topbar_gradient_start' => '#778899',
                'topbar_gradient_end' => '#aabbcc',
            ])
            ->assertOk()
            ->assertJsonPath('settings.name', 'Example Project')
            ->assertJsonPath('settings.authentication_type', 'split')
            ->assertJsonPath('settings.customer_authentication_type', 'card')
            ->assertJsonPath('settings.year', 2030)
            ->assertJsonPath('settings.sidenav_gradient_start', '#112233');

        $this->assertDatabaseHas('project_settings', [
            'id' => 1,
            'name' => 'Example Project',
            'authentication_type' => 'split',
            'customer_authentication_type' => 'card',
            'year' => 2030,
        ]);
    }

    public function test_super_admin_can_upload_system_logos(): void
    {
        Storage::fake('public');
        $user = User::factory()->create();
        $user->forceFill(['super_admin' => true])->save();

        $this
            ->actingAs($user, 'sanctum')
            ->post('/api/project-settings', [
                '_method' => 'PUT',
                'name' => 'Example Project',
                'author' => 'Example Author',
                'description' => 'Example description.',
                'authentication_type' => 'basic',
                'customer_authentication_type' => 'basic',
                'logo_dark' => UploadedFile::fake()->image('dark-logo.png', 300, 100),
                'auth_background' => UploadedFile::fake()->image('auth-background.jpg', 1920, 1080),
                'sidenav_image' => UploadedFile::fake()->image('sidenav.jpg', 600, 1200),
            ])
            ->assertOk()
            ->assertJsonPath('settings.logo_dark_url', fn ($url) => str_contains($url, '/storage/project-branding/'));

        $path = (string) \App\Models\ProjectSetting::find(1)->logo_dark_path;
        Storage::disk('public')->assertExists($path);
        Storage::disk('public')->assertExists((string) \App\Models\ProjectSetting::find(1)->auth_background_path);
        Storage::disk('public')->assertExists((string) \App\Models\ProjectSetting::find(1)->sidenav_image_path);
    }
}
