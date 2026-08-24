<?php

namespace App\Http\Controllers\Api\CustomerAuth;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\CustomerTwoFactorChallengeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function __invoke(Request $request, CustomerTwoFactorChallengeService $challenges): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $customer = Customer::where('email', $request->email)->first();

        if (! $customer || ! Hash::check($request->password, $customer->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (strtolower((string) $customer->status) === 'suspended') {
            return response()->json(['message' => 'This account is suspended.'], 403);
        }

        if ($customer->two_factor_enabled) {
            return response()->json([
                'two_factor_required' => true,
                ...$challenges->issue($customer, 'login', $customer->two_factor_method ?? 'email'),
                'user_status' => $customer->status ?? 'active',
            ]);
        }

        $customer->tokens()->delete();

        return response()->json([
            'token' => $customer->createToken('api-token')->plainTextToken,
            'user' => $this->serializeCustomer($customer),
        ]);
    }

    private function serializeCustomer(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'account_number' => $customer->account_number,
            'name' => $customer->name,
            'first_name' => $customer->first_name,
            'middle_name' => $customer->middle_name,
            'last_name' => $customer->last_name,
            'email' => $customer->email,
            'mobile_number' => $customer->mobile_number,
            'address' => $customer->address,
            'avatar_url' => $customer->avatar_url,
            'status' => $customer->status ?? 'active',
            'theme_preference' => $customer->theme_preference,
        ];
    }
}
