<?php

namespace Tests\Feature;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerAuthFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_registration_creates_account_number_and_token(): void
    {
        $response = $this->postJson('/api/customer/register', [
            'first_name' => '  Jane ',
            'middle_name' => ' Q. ',
            'last_name' => ' Public ',
            'email' => 'jane.customer@example.test',
            'mobile_number' => ' 09123456789 ',
            'address' => ' Metro Manila ',
            'password' => 'SecurePassword1!',
            'password_confirmation' => 'SecurePassword1!',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('user.name', 'Jane Q. Public')
            ->assertJsonPath('user.first_name', 'Jane')
            ->assertJsonPath('user.last_name', 'Public')
            ->assertJsonPath('user.status', 'active')
            ->assertJsonStructure(['token', 'user' => ['account_number']]);

        $this->assertDatabaseHas('customers', [
            'email' => 'jane.customer@example.test',
            'name' => 'Jane Q. Public',
        ]);
    }

    public function test_customer_login_rejects_suspended_accounts_and_returns_token(): void
    {
        $suspended = Customer::factory()->create([
            'email' => 'suspended.customer@example.test',
            'password' => 'Password1!',
            'status' => 'suspended',
        ]);

        $this->postJson('/api/customer/login', [
            'email' => $suspended->email,
            'password' => 'Password1!',
        ])->assertForbidden();

        $customer = Customer::factory()->create([
            'email' => 'active.customer@example.test',
            'password' => 'Password1!',
            'status' => 'active',
        ]);

        $this->postJson('/api/customer/login', [
            'email' => $customer->email,
            'password' => 'Password1!',
        ])
            ->assertOk()
            ->assertJsonStructure(['token', 'user'])
            ->assertJsonPath('user.account_number', $customer->account_number);

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/auth/user')
            ->assertOk()
            ->assertJsonPath('user.account_number', $customer->account_number)
            ->assertJsonPath('user.menu_permissions', []);
    }
}
