<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ThemePreferenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_update_their_theme_preference(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user, 'sanctum')
            ->putJson('/api/user/theme-preference', ['theme' => 'system']);

        $response
            ->assertOk()
            ->assertJsonPath('theme_preference', 'system');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'theme_preference' => 'system',
        ]);
    }

    public function test_theme_preference_rejects_unsupported_values(): void
    {
        $user = User::factory()->create();

        $this
            ->actingAs($user, 'sanctum')
            ->putJson('/api/user/theme-preference', ['theme' => 'sepia'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('theme');
    }

    public function test_theme_preference_requires_authentication(): void
    {
        $this
            ->putJson('/api/user/theme-preference', ['theme' => 'dark'])
            ->assertUnauthorized();
    }

    public function test_customer_can_update_their_theme_preference(): void
    {
        $customer = Customer::factory()->create();

        $response = $this
            ->actingAs($customer, 'sanctum')
            ->putJson('/api/user/theme-preference', ['theme' => 'dark']);

        $response
            ->assertOk()
            ->assertJsonPath('theme_preference', 'dark');

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'theme_preference' => 'dark',
        ]);
    }
}
