<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class PinController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'pin' => ['required', 'digits_between:4,6', 'confirmed'],
        ]);

        if (! Hash::check($data['current_password'], $request->user()->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $request->user()->forceFill(['pin' => Hash::make($data['pin'])])->save();

        return response()->json(['message' => 'PIN updated.', 'has_pin' => true]);
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate(['pin' => ['required', 'digits_between:4,6']]);
        $pin = $request->user()->pin;

        if (! is_string($pin) || ! Hash::check($data['pin'], $pin)) {
            throw ValidationException::withMessages(['pin' => ['The PIN is incorrect.']]);
        }

        return response()->json(['message' => 'Screen unlocked.']);
    }
}
