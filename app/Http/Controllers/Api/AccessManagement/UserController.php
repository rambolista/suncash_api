<?php

namespace App\Http\Controllers\Api\AccessManagement;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    /** GET /access-management/users */
    public function index(): JsonResponse
    {
        $users = User::with('roles:id,name')
            ->orderBy('name')
            ->get()
            ->map(fn($u) => $this->serializeUser($u));

        return response()->json($users);
    }

    /** POST /access-management/users */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $this->userHasPermission($user, '/apps/access-management/users', 'can_add')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $this->validatePayload($request);

        $user = User::create([
            'name'          => $this->buildFullName($data['first_name'], $data['middle_name'] ?? null, $data['last_name']),
            'first_name'    => $this->normalizeRequiredString($data['first_name']),
            'middle_name'   => $this->normalizeNullableString($data['middle_name'] ?? null),
            'last_name'     => $this->normalizeRequiredString($data['last_name']),
            'email'         => $data['email'],
            'password'      => Hash::make($data['password']),
            'mobile_number' => $this->normalizeNullableString($data['mobile_number'] ?? null),
            'address'       => $this->normalizeNullableString($data['address'] ?? null),
            'status'        => $this->normalizeStatus($data['status'] ?? 'active'),
        ]);

        if ($request->hasFile('avatar')) {
            $user->replaceAvatar($request->file('avatar'));
        }

        if (! empty($data['role_ids'])) {
            $user->roles()->sync($data['role_ids']);
        }

        return response()->json($this->serializeUser($user->fresh('roles:id,name')), 201);
    }

    /** PUT /access-management/users/{user} */
    public function update(Request $request, User $user): JsonResponse
    {
        $authUser = $request->user();
        if (! $this->userHasPermission($authUser, '/apps/access-management/users', 'can_edit')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $this->validatePayload($request, $user, true);

        $updatePayload = [];

        if (array_key_exists('first_name', $data)) {
            $updatePayload['first_name'] = $this->normalizeRequiredString($data['first_name']);
        }

        if (array_key_exists('middle_name', $data)) {
            $updatePayload['middle_name'] = $this->normalizeNullableString($data['middle_name']);
        }

        if (array_key_exists('last_name', $data)) {
            $updatePayload['last_name'] = $this->normalizeRequiredString($data['last_name']);
        }

        if (array_key_exists('email', $data)) {
            $updatePayload['email'] = $data['email'];
        }

        if (array_key_exists('mobile_number', $data)) {
            $updatePayload['mobile_number'] = $this->normalizeNullableString($data['mobile_number']);
        }

        if (array_key_exists('address', $data)) {
            $updatePayload['address'] = $this->normalizeNullableString($data['address']);
        }

        if (array_key_exists('status', $data)) {
            $updatePayload['status'] = $this->normalizeStatus($data['status']);
        }

        if (! empty($data['password'])) {
            $updatePayload['password'] = Hash::make($data['password']);
        }

        if (! empty($updatePayload)) {
            if (array_key_exists('first_name', $updatePayload) || array_key_exists('middle_name', $updatePayload) || array_key_exists('last_name', $updatePayload)) {
                $nextFirstName = $updatePayload['first_name'] ?? $user->first_name;
                $nextMiddleName = array_key_exists('middle_name', $updatePayload) ? $updatePayload['middle_name'] : $user->middle_name;
                $nextLastName = $updatePayload['last_name'] ?? $user->last_name;

                $updatePayload['name'] = $this->buildFullName($nextFirstName, $nextMiddleName, $nextLastName);
            }

            $user->update($updatePayload);
        }

        if ($request->hasFile('avatar')) {
            $user->replaceAvatar($request->file('avatar'));
        } elseif ($this->normalizeBoolean($request->input('clear_avatar'))) {
            $user->clearAvatar();
        }

        if (array_key_exists('role_ids', $data)) {
            $user->roles()->sync($data['role_ids'] ?? []);
        }

        return response()->json($this->serializeUser($user->fresh('roles:id,name')));
    }

    /** DELETE /access-management/users/{user} */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $authUser = $request->user();
        if (! $this->userHasPermission($authUser, '/apps/access-management/users', 'can_delete')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        // Prevent deleting the currently authenticated user
        if ($user->id === auth()->id()) {
            return response()->json(['message' => 'Cannot delete your own account.'], 422);
        }

        if ($user->avatar_path) {
            \Illuminate\Support\Facades\Storage::disk('public')->delete($user->avatar_path);
        }

        $user->delete();

        return response()->json(['message' => 'User deleted.']);
    }

    /** POST /access-management/users/{user}/roles — assign roles */
    public function assignRoles(Request $request, User $user): JsonResponse
    {
        $authUser = $request->user();
        if (! $this->userHasPermission($authUser, '/apps/access-management/users', 'can_edit')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $request->validate([
            'role_ids'   => ['required', 'array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
        ]);

        $user->roles()->sync($request->input('role_ids'));

        return response()->json([
            'message'  => 'Roles assigned.',
            'role_ids' => $user->roles()->pluck('id'),
        ]);
    }

    private function validatePayload(Request $request, ?User $user = null, bool $isUpdate = false): array
    {
        $payload = array_merge(
            $request->except(['role_ids', 'role_ids_json']),
            [
                'role_ids' => $this->normalizeRoleIds($request->input('role_ids_json', $request->input('role_ids'))),
                'avatar' => $request->file('avatar'),
                'clear_avatar' => $request->input('clear_avatar'),
            ]
        );

        $rules = [
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'unique:users,email' . ($user ? ',' . $user->id : '')],
            'mobile_number' => ['nullable', 'string', 'max:50'],
            'address'  => ['nullable', 'string', 'max:1000'],
            'avatar'   => ['nullable', 'image', 'max:5120'],
            'clear_avatar' => ['nullable', 'boolean'],
            'status'   => ['nullable', 'string', 'in:active,inactive,suspended,Active,Inactive,Suspended'],
            'role_ids' => ['nullable', 'array'],
            'role_ids.*' => ['integer', 'exists:roles,id'],
        ];

        if ($isUpdate) {
            $rules['first_name'][0] = 'sometimes';
            $rules['middle_name'] = ['sometimes', 'nullable', 'string', 'max:255'];
            $rules['last_name'][0] = 'sometimes';
            $rules['email'][0] = 'sometimes';
            $rules['password'] = ['nullable', Password::min(8)];
            $rules['mobile_number'][0] = 'sometimes';
            $rules['address'][0] = 'sometimes';
            $rules['avatar'] = ['sometimes', 'nullable', 'image', 'max:5120'];
            $rules['clear_avatar'][0] = 'sometimes';
        } else {
            $rules['password'] = ['required', Password::min(8)];
        }

        return Validator::make($payload, $rules)->validate();
    }

    private function normalizeRoleIds(mixed $roleIds): array
    {
        if (is_string($roleIds)) {
            $decoded = json_decode($roleIds, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                $roleIds = $decoded;
            }
        }

        if (! is_array($roleIds)) {
            return [];
        }

        return array_values($roleIds);
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizeRequiredString(mixed $value): string
    {
        return trim((string) $value);
    }

    private function serializeUser(User $user): array
    {
        return [
            'id'            => $user->id,
            'name'          => $user->name,
            'first_name'    => $user->first_name,
            'middle_name'   => $user->middle_name,
            'last_name'     => $user->last_name,
            'email'         => $user->email,
            'mobile_number' => $user->mobile_number,
            'address'       => $user->address,
            'avatar_url'    => $user->avatar_url,
            'status'        => $user->status ?? 'active',
            'super_admin'   => (bool) $user->super_admin,
            'theme_preference' => $user->theme_preference,
            'updated_at'    => optional($user->updated_at)->toISOString(),
            'role_ids'      => $user->roles->pluck('id')->values()->all(),
            'roles'         => $user->roles->map(fn($role) => ['id' => $role->id, 'name' => $role->name])->values()->all(),
        ];
    }

    private function normalizeStatus(mixed $value): string
    {
        if (! is_string($value)) {
            return 'active';
        }

        $normalized = strtolower(trim($value));

        return in_array($normalized, ['active', 'inactive', 'suspended'], true)
            ? $normalized
            : 'active';
    }

    private function normalizeBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        if (is_int($value)) {
            return $value === 1;
        }

        return false;
    }

    private function buildFullName(?string $firstName, ?string $middleName, ?string $lastName): string
    {
        $parts = array_filter([$firstName, $middleName, $lastName], fn($part) => is_string($part) && trim($part) !== '');

        return implode(' ', array_map('trim', $parts));
    }
}
