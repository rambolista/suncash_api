<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\ProfileChangeHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class UserProfileController extends Controller
{
    /**
     * GET /api/user/profile
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user()->loadMissing('roles:id,name'),
        ]);
    }

    /**
     * PUT /api/user/profile
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'first_name' => ['sometimes', 'required', 'string', 'max:255'],
            'middle_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'last_name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'mobile_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ]);

        $updatePayload = $request->only('email');

        if ($request->exists('first_name')) {
            $updatePayload['first_name'] = trim((string) $request->input('first_name'));
        }

        if ($request->exists('middle_name')) {
            $updatePayload['middle_name'] = $this->normalizeNullableString($request->input('middle_name'));
        }

        if ($request->exists('last_name')) {
            $updatePayload['last_name'] = trim((string) $request->input('last_name'));
        }

        if ($request->exists('first_name') || $request->exists('middle_name') || $request->exists('last_name')) {
            $updatePayload['name'] = collect([
                $updatePayload['first_name'] ?? $user->first_name,
                array_key_exists('middle_name', $updatePayload) ? $updatePayload['middle_name'] : $user->middle_name,
                $updatePayload['last_name'] ?? $user->last_name,
            ])->filter(fn ($part) => is_string($part) && $part !== '')->implode(' ');
        }

        if ($request->exists('mobile_number')) {
            $updatePayload['mobile_number'] = $this->normalizeNullableString($request->input('mobile_number'));
        }

        if ($request->exists('address')) {
            $updatePayload['address'] = $this->normalizeNullableString($request->input('address'));
        }

        $changes = [];

        foreach ($updatePayload as $field => $value) {
            if ($field === 'name') {
                continue;
            }

            $originalValue = $user->getAttribute($field);

            if ($originalValue !== $value) {
                $changes[$field] = [
                    'from' => $originalValue,
                    'to' => $value,
                ];
            }
        }

        DB::transaction(function () use ($user, $updatePayload, $changes): void {
            $user->update($updatePayload);

            if ($changes !== []) {
                ProfileChangeHistory::create([
                    'user_id' => $user->id,
                    'changes' => $changes,
                ]);
            }
        });

        return response()->json([
            'user' => $user->fresh()->loadMissing('roles:id,name'),
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $history = ProfileChangeHistory::query()
            ->whereBelongsTo($request->user())
            ->latest()
            ->limit(50)
            ->get(['id', 'changes', 'created_at']);

        return response()->json([
            'history' => $history,
        ]);
    }

    public function updateAvatar(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $request->validate([
            'avatar' => ['required', 'image', 'max:5120'],
        ]);

        $user->replaceAvatar($request->file('avatar'));

        return response()->json([
            'avatar_url' => $user->fresh()->avatar_url,
            'user' => $user->fresh()->loadMissing('roles:id,name'),
        ]);
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
