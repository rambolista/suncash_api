<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class DeleteAccountController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate(['current_password' => ['required', 'string']]);
        $user = $request->user();

        if (strtolower((string) $user->status) !== 'inactive') {
            throw ValidationException::withMessages([
                'status' => ['Only inactive accounts can be deleted.'],
            ]);
        }

        if (! Hash::check($data['current_password'], $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['The current password is incorrect.'],
            ]);
        }

        $user->clearAvatar();

        DB::transaction(function () use ($user): void {
            $user->tokens()->delete();
            $user->twoFactorChallenges()->delete();
            $user->roles()->detach();
            $user->notifications()->delete();
            $user->delete();
        });

        return response()->json(['message' => 'Account deleted.']);
    }
}
