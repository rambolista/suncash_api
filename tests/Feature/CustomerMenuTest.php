<?php

namespace Tests\Feature;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerMenuTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_menu_endpoint_returns_visible_menus(): void
    {
        $customer = Customer::factory()->create();

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/customer-menus')
            ->assertOk()
            ->assertJsonCount(7)
            ->assertJsonPath('0.label', 'Overview');
    }
}
