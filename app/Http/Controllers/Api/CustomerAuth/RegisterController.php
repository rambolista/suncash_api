<?php

namespace App\Http\Controllers\Api\CustomerAuth;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class RegisterController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:customers,email'],
            'mobile_number' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:1000'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $firstName = trim($request->string('first_name')->toString());
        $middleName = trim($request->string('middle_name')->toString()) ?: null;
        $lastName = trim($request->string('last_name')->toString());

        $customer = Customer::create([
            'name' => collect([$firstName, $middleName, $lastName])->filter()->implode(' '),
            'first_name' => $firstName,
            'middle_name' => $middleName,
            'last_name' => $lastName,
            'email' => $request->email,
            'mobile_number' => $request->filled('mobile_number') ? trim($request->mobile_number) : null,
            'address' => $request->filled('address') ? trim($request->address) : null,
            'password' => Hash::make($request->password),
            'status' => 'active',
        ]);

        return response()->json([
            'token' => $customer->createToken('api-token')->plainTextToken,
            'user' => $this->serializeCustomer($customer),
        ], 201);
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
