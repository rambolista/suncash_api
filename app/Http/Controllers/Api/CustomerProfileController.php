<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CustomerProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();

        return response()->json([
            'customer' => $this->serializeCustomer($customer),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        /** @var Customer $customer */
        $customer = $request->user();

        $data = $request->validate([
            'first_name' => ['sometimes', 'required', 'string', 'max:255'],
            'middle_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'last_name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255', Rule::unique('customers')->ignore($customer->id)],
            'mobile_number' => ['sometimes', 'nullable', 'string', 'max:50'],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'avatar' => ['sometimes', 'nullable', 'image', 'max:5120'],
            'clear_avatar' => ['sometimes', 'boolean'],
        ]);

        $updatePayload = [];

        if ($request->exists('first_name')) {
            $updatePayload['first_name'] = trim((string) $request->input('first_name'));
        }

        if ($request->exists('middle_name')) {
            $updatePayload['middle_name'] = $this->normalizeNullableString($request->input('middle_name'));
        }

        if ($request->exists('last_name')) {
            $updatePayload['last_name'] = trim((string) $request->input('last_name'));
        }

        if ($request->exists('email')) {
            $updatePayload['email'] = $data['email'];
        }

        if ($request->exists('mobile_number')) {
            $updatePayload['mobile_number'] = $this->normalizeNullableString($request->input('mobile_number'));
        }

        if ($request->exists('address')) {
            $updatePayload['address'] = $this->normalizeNullableString($request->input('address'));
        }

        if ($request->exists('first_name') || $request->exists('middle_name') || $request->exists('last_name')) {
            $updatePayload['name'] = collect([
                $updatePayload['first_name'] ?? $customer->first_name,
                array_key_exists('middle_name', $updatePayload) ? $updatePayload['middle_name'] : $customer->middle_name,
                $updatePayload['last_name'] ?? $customer->last_name,
            ])->filter(fn ($part) => is_string($part) && $part !== '')->implode(' ');
        }

        DB::transaction(function () use ($customer, $updatePayload, $request): void {
            if ($updatePayload !== []) {
                $customer->update($updatePayload);
            }

            if ($request->hasFile('avatar')) {
                $customer->replaceAvatar($request->file('avatar'));
            } elseif ($this->normalizeBoolean($request->input('clear_avatar'))) {
                $customer->clearAvatar();
            }
        });

        return response()->json([
            'customer' => $this->serializeCustomer($customer->fresh()),
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
            'updated_at' => optional($customer->updated_at)->toISOString(),
        ];
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
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
}
