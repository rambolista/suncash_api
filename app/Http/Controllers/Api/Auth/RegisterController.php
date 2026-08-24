<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class RegisterController extends Controller
{
    /**
     * Register a new user and issue a Sanctum API token.
     *
     * POST /api/auth/register
     */
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users'],
            'mobile_number' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:1000'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $firstName = trim($request->string('first_name')->toString());
        $middleName = trim($request->string('middle_name')->toString()) ?: null;
        $lastName = trim($request->string('last_name')->toString());

        $user = User::create([
            'name' => collect([$firstName, $middleName, $lastName])->filter()->implode(' '),
            'first_name' => $firstName,
            'middle_name' => $middleName,
            'last_name' => $lastName,
            'email' => $request->email,
            'mobile_number' => $request->filled('mobile_number') ? trim($request->mobile_number) : null,
            'address' => $request->filled('address') ? trim($request->address) : null,
            'password' => Hash::make($request->password),
        ]);

        $token = $user->createToken('api-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user->loadMissing('roles:id,name'),
        ], 201);
    }
}
