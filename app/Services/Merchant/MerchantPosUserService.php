<?php

namespace App\Services\Merchant;

use App\Models\Mysuncash\Branch;
use App\Models\Mysuncash\Merchant;
use App\Models\Mysuncash\MerchantTerminalUser;
use App\Services\LegacyCredentialCipher;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MerchantPosUserService
{
    private function findMerchantOrFail(int $merchantId): Merchant
    {
        $merchant = Merchant::find($merchantId);
        if (! $merchant) {
            throw ValidationException::withMessages(['id' => ['Merchant not found.']]);
        }

        return $merchant;
    }

    private function present(MerchantTerminalUser $user): array
    {
        return [
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'username' => $user->user_name,
            'branch_id' => $user->branch_id,
            'branch_user_type_id' => $user->branch_user_type_id,
            'branch_user_type' => MerchantTerminalUser::BRANCH_USER_TYPES[(int) $user->branch_user_type_id] ?? null,
            'application_access' => $user->application_access,
            'all_access_branch' => $user->ALL_ACCESS_BRANCH === '1',
            'status' => (int) $user->user_status_id === 0 ? 'active' : 'inactive',
        ];
    }

    public function listPosUsers(int $merchantId): array
    {
        $this->findMerchantOrFail($merchantId);

        return MerchantTerminalUser::where('merchant_id', $merchantId)
            ->where(function ($query) {
                $query->whereNull('user_subtype')->orWhere('user_subtype', '');
            })
            ->orderBy('creation_date')
            ->get()
            ->map(fn (MerchantTerminalUser $user) => $this->present($user))
            ->all();
    }

    public function listBranchesForDropdown(int $merchantId): array
    {
        return Branch::where('client_record_id', $merchantId)
            ->where('status', Branch::STATUS_ACTIVE)
            ->orderBy('branch_code')
            ->get(['id', 'branch_code', 'description'])
            ->toArray();
    }

    private function resolveChannel(string $applicationAccess): string
    {
        return $applicationAccess === 'retail' ? 'retailwebpos' : $applicationAccess;
    }

    /**
     * @throws ValidationException
     */
    public function createPosUser(int $merchantId, array $data, string $actorId): array
    {
        $merchant = $this->findMerchantOrFail($merchantId);

        $username = trim((string) ($data['username'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $errors = [];
        if (! filled($data['first_name'] ?? null)) {
            $errors['first_name'] = ['First name is required.'];
        }
        if (! filled($data['last_name'] ?? null)) {
            $errors['last_name'] = ['Last name is required.'];
        }
        if (! filled($username)) {
            $errors['username'] = ['Username is required.'];
        } elseif (MerchantTerminalUser::where('user_name', $username)->exists()) {
            $errors['username'] = ['Username already exists.'];
        }
        if (! filled($password)) {
            $errors['password'] = ['Password is required.'];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $applicationAccess = $data['application_access'] ?? 'retail';
        $now = now();
        [$encryptedPassword, $userKey] = array_values(LegacyCredentialCipher::encrypt($password));

        $user = DB::connection('mysuncash')->transaction(function () use ($merchant, $data, $username, $encryptedPassword, $userKey, $applicationAccess, $actorId, $now) {
            $user = MerchantTerminalUser::create([
                'merchant_id' => $merchant->id,
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'location' => Branch::find($data['branch_id'] ?? null)?->description ?? '',
                'user_name' => $username,
                'password' => $encryptedPassword,
                'user_status_id' => 0,
                'creation_date' => $now,
                'modification_date' => $now,
                'branch_id' => $data['branch_id'] ?? null,
                'branch_user_type_id' => $data['branch_user_type_id'] ?? 2,
                'created_by' => $actorId,
                'ALL_ACCESS_BRANCH' => ! empty($data['all_access_branch']) ? '1' : '0',
                'require_pw_change' => 1,
                'application_access' => $applicationAccess,
            ]);

            DB::connection('mysuncash')->table('user_keys')->insert([
                'user_id' => $user->id,
                'key' => $userKey,
                'channel' => $this->resolveChannel($applicationAccess),
            ]);

            return $user;
        });

        return $this->present($user);
    }

    /**
     * @throws ValidationException
     */
    public function updatePosUser(int $merchantId, int $userId, array $data, string $actorId): array
    {
        $user = MerchantTerminalUser::where('merchant_id', $merchantId)->where('id', $userId)->first();
        if (! $user) {
            throw ValidationException::withMessages(['id' => ['User not found.']]);
        }

        $username = trim((string) ($data['username'] ?? $user->user_name));
        if ($username !== $user->user_name && MerchantTerminalUser::where('user_name', $username)->exists()) {
            throw ValidationException::withMessages(['username' => ['Username already exists.']]);
        }

        $applicationAccess = $data['application_access'] ?? $user->application_access;

        DB::connection('mysuncash')->transaction(function () use ($user, $data, $username, $applicationAccess, $actorId) {
            $attributes = [
                'first_name' => $data['first_name'] ?? $user->first_name,
                'last_name' => $data['last_name'] ?? $user->last_name,
                'user_name' => $username,
                'branch_id' => $data['branch_id'] ?? $user->branch_id,
                'branch_user_type_id' => $data['branch_user_type_id'] ?? $user->branch_user_type_id,
                'user_status_id' => array_key_exists('user_status_id', $data) ? $data['user_status_id'] : $user->user_status_id,
                'ALL_ACCESS_BRANCH' => array_key_exists('all_access_branch', $data) ? (($data['all_access_branch']) ? '1' : '0') : $user->ALL_ACCESS_BRANCH,
                'application_access' => $applicationAccess,
                'modification_date' => now(),
            ];

            if (filled($data['password'] ?? null)) {
                [$encryptedPassword, $userKey] = array_values(LegacyCredentialCipher::encrypt($data['password']));
                $attributes['password'] = $encryptedPassword;
                $attributes['require_pw_change'] = 1;

                $channel = $this->resolveChannel($applicationAccess);
                $updated = DB::connection('mysuncash')->table('user_keys')
                    ->where('user_id', $user->id)->where('channel', $channel)
                    ->update(['key' => $userKey]);
                if (! $updated) {
                    DB::connection('mysuncash')->table('user_keys')->insert(['user_id' => $user->id, 'key' => $userKey, 'channel' => $channel]);
                }
            }

            $user->update($attributes);
        });

        return $this->present($user);
    }

    /**
     * @throws ValidationException
     */
    public function deletePosUser(int $merchantId, int $userId): void
    {
        $deleted = MerchantTerminalUser::where('merchant_id', $merchantId)->where('id', $userId)->delete();
        if (! $deleted) {
            throw ValidationException::withMessages(['id' => ['User not found.']]);
        }
    }
}
