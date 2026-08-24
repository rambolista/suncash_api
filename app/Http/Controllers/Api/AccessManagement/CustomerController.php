<?php

namespace App\Http\Controllers\Api\AccessManagement;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (! $this->userHasPermission($request->user(), '/apps/customers', 'can_view')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $customers = Customer::query()
            ->orderBy('account_number')
            ->get()
            ->map(fn (Customer $customer) => $this->serializeCustomer($customer));

        return response()->json($customers);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $this->userHasPermission($request->user(), '/apps/customers', 'can_add')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $this->validatePayload($request);

        $customer = Customer::create([
            'account_number' => $data['account_number'] ?? null,
            'name' => $this->buildFullName($data['first_name'], $data['middle_name'] ?? null, $data['last_name']),
            'first_name' => $this->normalizeRequiredString($data['first_name']),
            'middle_name' => $this->normalizeNullableString($data['middle_name'] ?? null),
            'last_name' => $this->normalizeRequiredString($data['last_name']),
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'mobile_number' => $this->normalizeNullableString($data['mobile_number'] ?? null),
            'address' => $this->normalizeNullableString($data['address'] ?? null),
            'status' => $this->normalizeStatus($data['status'] ?? 'active'),
        ]);

        if ($request->hasFile('avatar')) {
            $customer->replaceAvatar($request->file('avatar'));
        }

        return response()->json($this->serializeCustomer($customer->fresh()), 201);
    }

    public function show(Request $request, Customer $customer): JsonResponse
    {
        if (! $this->userHasPermission($request->user(), '/apps/customers', 'can_view')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json($this->serializeCustomer($customer));
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        if (! $this->userHasPermission($request->user(), '/apps/customers', 'can_edit')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $this->validatePayload($request, $customer, true);
        $updatePayload = [];

        if (array_key_exists('account_number', $data) && filled($data['account_number'])) {
            $updatePayload['account_number'] = trim((string) $data['account_number']);
        }

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

        if (array_key_exists('first_name', $updatePayload) || array_key_exists('middle_name', $updatePayload) || array_key_exists('last_name', $updatePayload)) {
            $nextFirstName = $updatePayload['first_name'] ?? $customer->first_name;
            $nextMiddleName = array_key_exists('middle_name', $updatePayload) ? $updatePayload['middle_name'] : $customer->middle_name;
            $nextLastName = $updatePayload['last_name'] ?? $customer->last_name;
            $updatePayload['name'] = $this->buildFullName($nextFirstName, $nextMiddleName, $nextLastName);
        }

        if (! empty($updatePayload)) {
            $customer->update($updatePayload);
        }

        if ($request->hasFile('avatar')) {
            $customer->replaceAvatar($request->file('avatar'));
        } elseif ($this->normalizeBoolean($request->input('clear_avatar'))) {
            $customer->clearAvatar();
        }

        return response()->json($this->serializeCustomer($customer->fresh()));
    }

    public function destroy(Request $request, Customer $customer): JsonResponse
    {
        if (! $this->userHasPermission($request->user(), '/apps/customers', 'can_delete')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if ($customer->avatar_path) {
            Storage::disk('public')->delete($customer->avatar_path);
        }

        $customer->delete();

        return response()->json(['message' => 'Customer deleted.']);
    }

    private function validatePayload(Request $request, ?Customer $customer = null, bool $isUpdate = false): array
    {
        $payload = array_merge(
            $request->except(['avatar']),
            [
                'avatar' => $request->file('avatar'),
            ]
        );

        $rules = [
            'account_number' => ['nullable', 'string', 'max:50', 'unique:customers,account_number' . ($customer ? ',' . $customer->id : '')],
            'first_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:customers,email' . ($customer ? ',' . $customer->id : '')],
            'mobile_number' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:1000'],
            'avatar' => ['nullable', 'image', 'max:5120'],
            'clear_avatar' => ['nullable', 'boolean'],
            'status' => ['nullable', 'string', 'in:active,inactive,suspended,Active,Inactive,Suspended'],
        ];

        if ($isUpdate) {
            $rules['account_number'][0] = 'sometimes';
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
        $parts = array_filter([$firstName, $middleName, $lastName], fn ($part) => is_string($part) && trim($part) !== '');

        return implode(' ', array_map('trim', $parts));
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
            'updated_at' => optional($customer->updated_at)->toISOString(),
        ];
    }
}
