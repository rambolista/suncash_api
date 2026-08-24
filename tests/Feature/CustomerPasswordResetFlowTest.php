<?php

namespace Tests\Feature;

use App\Models\Customer;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CustomerPasswordResetFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_forgot_password_sends_customer_reset_link(): void
    {
        Notification::fake();
        $customer = Customer::factory()->create();

        $this->postJson('/api/customer/forgot-password', [
            'email' => $customer->email,
        ])->assertOk();

        Notification::assertSentTo(
            $customer,
            ResetPassword::class,
            function (ResetPassword $notification) use ($customer): bool {
                $url = $notification->toMail($customer)->actionUrl;

                return str_starts_with($url, config('app.frontend_url').'/customer/new-pass?')
                    && str_contains($url, 'token=')
                    && str_contains($url, 'email='.rawurlencode($customer->email));
            }
        );
    }
}
