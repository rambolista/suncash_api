<?php

namespace App\Services\Tools;

use App\Models\ActivityLog;
use App\Models\Mysuncash\Customer;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

/**
 * "Tools > Customer Management > Reset Pin" (legacy `btnResetPinCode`,
 * `scripts.js:964-1004`). Legacy fires this straight from the browser as a
 * live GET to a hardcoded `https://prod.mysuncash.com/api/chp.php?method=
 * reset_pinv2` — regardless of which environment the admin panel itself is
 * running in, so testing this flow from a stage/dev copy of legacy
 * silently hits production. Not replicated as-is: gated behind
 * `services.reset_pin.enabled` (default false, same pattern as the SMS
 * gateways/ComplyAdvantage), calls a configurable URL server-side instead
 * of the browser calling a hardcoded one directly, and every attempt is
 * logged.
 */
class ResetPinService
{
    /** @throws ValidationException */
    public function reset(int $customerId, User $actor): array
    {
        $customer = Customer::find($customerId);
        if (! $customer) {
            throw ValidationException::withMessages(['id' => ['Customer not found.']]);
        }
        if (blank($customer->birthday)) {
            throw ValidationException::withMessages(['birthday' => ['This customer has no birthday on file — required to reset the PIN.']]);
        }
        if (blank($customer->mobile)) {
            throw ValidationException::withMessages(['mobile' => ['This customer has no mobile number on file.']]);
        }

        $actorName = $actor->name ?? $actor->email;

        if (! config('services.reset_pin.enabled')) {
            ActivityLog::recordAction($actor, 'Tools - Customer Management', 'reset_pin_simulated', "Simulated PIN reset for customer {$customerId} (reset endpoint disabled in this environment)");

            return ['simulated' => true, 'message' => 'PIN reset is disabled in this environment — no PIN was actually reset.'];
        }

        $response = Http::get(rtrim((string) config('services.reset_pin.url'), '/'), [
            'method' => 'reset_pinv2',
            'P01' => $customer->mobile,
            'P02' => $customer->first_name,
            'P03' => $customer->last_name,
            'P04' => $customer->birthday,
        ]);

        ActivityLog::recordAction($actor, 'Tools - Customer Management', $response->successful() ? 'reset_pin' : 'reset_pin_failed', "Reset PIN for customer {$customerId} (requested by {$actorName})");

        if (! $response->successful()) {
            throw ValidationException::withMessages(['id' => ['Unable to reset PIN.']]);
        }

        return ['simulated' => false, 'message' => 'PIN has been reset.'];
    }
}
