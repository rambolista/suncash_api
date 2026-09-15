<?php

namespace App\Services\Merchant;

use App\Mail\MerchantPasswordResetMail;
use App\Models\Mysuncash\Merchant;
use App\Models\Mysuncash\MerchantPrincipalInfo;
use App\Models\Mysuncash\PasswordHistory;
use App\Models\Mysuncash\ServicesPermission;
use App\Models\Mysuncash\SystemService;
use App\Models\Mysuncash\UserAccount;
use App\Services\LegacyCredentialCipher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Backs the per-merchant action buttons from the legacy client_management
 * list (Principal Info, Password reset, User Management, Deactivate, Ezpay
 * Access, Services Permission) that aren't part of the main registration/
 * edit flow MerchantRegistrationService covers.
 */
class MerchantOperationsService
{
    /** Mirrors the fixed role list on legacy's merchant_user_registration.php Roles dropdown. */
    public const USER_TYPES = [
        1 => 'Client Admin',
        4 => 'External',
        5 => 'User',
        6 => 'Support',
        7 => 'Manager',
    ];

    private function findMerchantOrFail(int $merchantId): Merchant
    {
        $merchant = Merchant::find($merchantId);
        if (! $merchant) {
            throw ValidationException::withMessages(['id' => ['Merchant not found.']]);
        }

        return $merchant;
    }

    // ── Principal Info ──────────────────────────────────────────────────────

    public function getPrincipalInfo(int $merchantId): ?array
    {
        $principal = MerchantPrincipalInfo::where('merchant_id', $merchantId)->first();

        return $principal?->only(['fname', 'lname', 'position', 'equity', 'email', 'mobile', 'address1', 'address2', 'city', 'state', 'zip']);
    }

    /**
     * @throws ValidationException
     */
    public function savePrincipalInfo(int $merchantId, array $data): array
    {
        $this->findMerchantOrFail($merchantId);

        $required = ['fname', 'lname', 'position', 'equity', 'email', 'mobile', 'address1', 'city', 'state', 'zip'];
        $errors = [];
        foreach ($required as $field) {
            if (! filled($data[$field] ?? null)) {
                $errors[$field] = ['This field is required.'];
            }
        }
        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }

        if (filled($data['email']) && ! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['email' => ['Enter a valid e-mail address.']]);
        }

        $attributes = array_intersect_key($data, array_flip([
            'fname', 'lname', 'position', 'equity', 'email', 'mobile', 'address1', 'address2', 'city', 'state', 'zip',
        ]));
        $attributes['address2'] = $attributes['address2'] ?? '';

        $principal = MerchantPrincipalInfo::updateOrCreate(['merchant_id' => $merchantId], $attributes);

        return $principal->only(['fname', 'lname', 'position', 'equity', 'email', 'mobile', 'address1', 'address2', 'city', 'state', 'zip']);
    }

    // ── Password reset ──────────────────────────────────────────────────────

    /**
     * Generates a new random password and updates the account's credentials
     * (password, user_keys, password_history) — shared by resetPassword()
     * (the merchant list's single "reset password" action, targeting the
     * merchant's default login) and resetUserPassword() (User Management
     * tab, any specific portal user). Mirrors accounts_model::reset_password.
     * Returns the new plaintext password; callers e-mail it and never persist it.
     */
    private function applyNewPassword(UserAccount $userAccount, Merchant $merchant, string $actorId): string
    {
        $newPassword = strtoupper(Str::random(6)) . Str::lower(Str::random(2));
        [$encryptedPassword, $userKey] = array_values(LegacyCredentialCipher::encrypt($newPassword));
        $now = now();

        DB::connection('mysuncash')->transaction(function () use ($merchant, $userAccount, $actorId, $now, $encryptedPassword, $userKey, $newPassword) {
            $userAccount->update([
                'password' => $encryptedPassword,
                'user_status_id' => 0,
                'require_pw_change' => 1,
                'user_id_modified' => $actorId,
                'modification_date' => $now,
                'pw_expiration' => $now->copy()->addDays(90)->toDateString(),
            ]);

            PasswordHistory::create([
                'user_id' => $userAccount->id,
                'password' => crypt($newPassword, '$6$rounds=6000$thisisnotaP4sswo$'),
            ]);

            $channel = (int) $merchant->merchant_type_id === 3 ? 'charity' : 'business';
            $updated = DB::connection('mysuncash')->table('user_keys')
                ->where('user_id', $userAccount->id)
                ->where('channel', $channel)
                ->update(['key' => $userKey]);

            if (! $updated) {
                DB::connection('mysuncash')->table('user_keys')->insert([
                    'user_id' => $userAccount->id,
                    'key' => $userKey,
                    'channel' => $channel,
                ]);
            }
        });

        return $newPassword;
    }

    /**
     * Generates a new random password for the merchant's default portal
     * login, e-mails it to the merchant's contact address, and returns only
     * the username + confirmation (never the password itself) to the admin —
     * mirrors tools_model::reset_merchant_password.
     *
     * @throws ValidationException
     */
    public function resetPassword(int $merchantId, string $actorId): array
    {
        $merchant = $this->findMerchantOrFail($merchantId);
        $userAccount = $merchant->userAccounts()->orderBy('id')->first();
        $email = $merchant->merchantDetail?->contactemail;

        if (! $userAccount) {
            throw ValidationException::withMessages(['id' => ['This merchant has no portal login to reset.']]);
        }
        if (! filled($email)) {
            throw ValidationException::withMessages(['id' => ['The contact e-mail address for this merchant is not set.']]);
        }

        $newPassword = $this->applyNewPassword($userAccount, $merchant, $actorId);
        Mail::to($email)->send(new MerchantPasswordResetMail($userAccount->user_name, $newPassword));

        return ['username' => $userAccount->user_name, 'email' => $email];
    }

    // ── Portal user management ──────────────────────────────────────────────

    private function presentUser(UserAccount $user): array
    {
        return [
            'id' => $user->id,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'username' => $user->user_name,
            'email' => $user->email_address,
            'status' => (int) $user->user_status_id === 0 ? 'active' : 'inactive',
            'user_type_id' => (int) $user->user_type_id,
            'user_type' => self::USER_TYPES[(int) $user->user_type_id] ?? null,
            'creation_date' => $user->creation_date,
        ];
    }

    public function listUsers(int $merchantId): array
    {
        return Merchant::findOrFail($merchantId)
            ->userAccounts()
            ->orderBy('creation_date')
            ->get()
            ->map(fn (UserAccount $user) => $this->presentUser($user))
            ->all();
    }

    /**
     * @throws ValidationException
     */
    public function addUser(int $merchantId, array $data, string $actorId): array
    {
        $merchant = $this->findMerchantOrFail($merchantId);

        $required = ['first_name', 'last_name', 'username', 'password', 'email', 'user_type_id'];
        $errors = [];
        foreach ($required as $field) {
            if (! filled($data[$field] ?? null)) {
                $errors[$field] = ['This field is required.'];
            }
        }
        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }

        if (! array_key_exists((int) $data['user_type_id'], self::USER_TYPES)) {
            throw ValidationException::withMessages(['user_type_id' => ['Select a valid role.']]);
        }

        if (filled($data['email']) && ! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['email' => ['Enter a valid e-mail address.']]);
        }

        if (! (strlen($data['password']) >= 8 && strlen($data['password']) <= 20 && preg_match('#[0-9]#', $data['password']) && preg_match('#[A-Z]#', $data['password']))) {
            throw ValidationException::withMessages(['password' => ['Password must be 8-20 characters and include at least one number and one uppercase letter.']]);
        }

        if (UserAccount::where('user_name', $data['username'])->exists()) {
            throw ValidationException::withMessages(['username' => ['Username already exists.']]);
        }

        $now = now();
        [$encryptedPassword, $userKey] = array_values(LegacyCredentialCipher::encrypt($data['password']));

        $user = DB::connection('mysuncash')->transaction(function () use ($merchant, $data, $actorId, $now, $encryptedPassword, $userKey) {
            $user = UserAccount::create([
                'user_type_id' => (int) $data['user_type_id'],
                'user_reference' => $merchant->id,
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'user_name' => $data['username'],
                'password' => $encryptedPassword,
                'user_status_id' => 0,
                'user_id_create' => $actorId,
                'user_id_modified' => $actorId,
                'email_address' => $data['email'],
                'pw_expiration' => $now->copy()->addDays(90)->toDateString(),
                'creation_date' => $now,
                'modification_date' => $now,
            ]);

            DB::connection('mysuncash')->table('user_keys')->insert([
                'user_id' => $user->id,
                'key' => $userKey,
                'channel' => (int) $merchant->merchant_type_id === 3 ? 'charity' : 'business',
            ]);

            return $user;
        });

        return $this->presentUser($user);
    }

    /**
     * Mirrors accounts_model::update_merchant_user — legacy's edit form only
     * exposes username, e-mail, and status (role is set once at registration
     * and never re-editable here).
     *
     * @throws ValidationException
     */
    public function updateUser(int $merchantId, int $userId, array $data, string $actorId): array
    {
        $merchant = $this->findMerchantOrFail($merchantId);
        $user = $merchant->userAccounts()->where('id', $userId)->first();
        if (! $user) {
            throw ValidationException::withMessages(['id' => ['User not found.']]);
        }

        $errors = [];
        if (! filled($data['username'] ?? null)) {
            $errors['username'] = ['Username is required.'];
        }
        if (! filled($data['email'] ?? null)) {
            $errors['email'] = ['E-mail is required.'];
        } elseif (! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = ['Enter a valid e-mail address.'];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        if (UserAccount::where('user_name', $data['username'])->where('id', '!=', $userId)->exists()) {
            throw ValidationException::withMessages(['username' => ['Username already exists.']]);
        }

        $user->update([
            'user_name' => $data['username'],
            'email_address' => $data['email'],
            'user_status_id' => ($data['status'] ?? 'active') === 'active' ? 0 : 1,
            'user_id_modified' => $actorId,
            'modification_date' => now(),
        ]);

        return $this->presentUser($user);
    }

    /**
     * Per-user password reset for the User Management tab — e-mails the new
     * password to that user's own address (unlike resetPassword() above,
     * which targets the merchant's contact e-mail for the default login).
     *
     * @throws ValidationException
     */
    public function resetUserPassword(int $merchantId, int $userId, string $actorId): array
    {
        $merchant = $this->findMerchantOrFail($merchantId);
        $userAccount = $merchant->userAccounts()->where('id', $userId)->first();

        if (! $userAccount) {
            throw ValidationException::withMessages(['id' => ['User not found.']]);
        }
        if (! filled($userAccount->email_address)) {
            throw ValidationException::withMessages(['id' => ["This user's e-mail address is not set."]]);
        }

        $newPassword = $this->applyNewPassword($userAccount, $merchant, $actorId);
        Mail::to($userAccount->email_address)->send(new MerchantPasswordResetMail($userAccount->user_name, $newPassword));

        return ['username' => $userAccount->user_name, 'email' => $userAccount->email_address];
    }

    // ── Activate / deactivate ───────────────────────────────────────────────

    /**
     * `client_status_id`: 0=active, 1=inactive (schema default, unused by
     * any admin action), 2=deactivated via this admin action, -1=self-
     * registered/never activated (see MerchantDashboardController). Was
     * previously setting 1 instead of 2 on deactivate — a real bug, not a
     * legacy quirk to replicate: the Merchant Management list's default
     * view only shows `client_status_id` 0 or 2 (matching legacy's own
     * `client_management.php` filter, see MerchantRegistrationService),
     * so a merchant deactivated via this action was silently vanishing
     * from that list entirely instead of showing as Inactive.
     *
     * @throws ValidationException
     */
    public function toggleStatus(int $merchantId, string $actorId): array
    {
        $merchant = $this->findMerchantOrFail($merchantId);
        $newStatus = (int) $merchant->client_status_id === 0 ? 2 : 0;

        $merchant->update([
            'client_status_id' => $newStatus,
            'user_id_modify' => $actorId,
            'modification_date' => now(),
        ]);

        return ['client_status_id' => $newStatus, 'active' => $newStatus === 0];
    }

    // ── Ezpay access ─────────────────────────────────────────────────────────

    public const EZPAY_TRANSACTION_TYPES = [
        'sale' => 'Sale',
        'void' => 'Void',
        'load' => 'Load',
        'activate' => 'Register/Activate Card',
    ];

    public function getEzpayAccess(int $merchantId): array
    {
        $merchant = $this->findMerchantOrFail($merchantId);

        if (! filled($merchant->ezpay_access)) {
            return [];
        }

        $access = @unserialize($merchant->ezpay_access);

        return is_array($access) ? array_values(array_intersect($access, array_keys(self::EZPAY_TRANSACTION_TYPES))) : [];
    }

    /**
     * @throws ValidationException
     */
    public function updateEzpayAccess(int $merchantId, array $access, string $actorId): array
    {
        $merchant = $this->findMerchantOrFail($merchantId);

        $access = array_values(array_intersect($access, array_keys(self::EZPAY_TRANSACTION_TYPES)));

        $merchant->update([
            'ezpay_access' => serialize($access),
            'user_id_modify' => $actorId,
            'modification_date' => now(),
        ]);

        return $access;
    }

    // ── Services permission ─────────────────────────────────────────────────

    public function listServices(int $merchantId): array
    {
        $merchant = $this->findMerchantOrFail($merchantId);

        $grantedIds = $merchant->servicesPermissions()
            ->where('status', 'A')
            ->pluck('system_services_id')
            ->all();

        return SystemService::where('status', 'A')
            ->orderBy('name')
            ->get()
            ->map(fn (SystemService $service) => [
                'id' => $service->id,
                'code' => $service->code,
                'name' => $service->name,
                'granted' => in_array($service->id, $grantedIds, true),
            ])
            ->all();
    }

    /**
     * @throws ValidationException
     */
    public function updateServices(int $merchantId, array $serviceIds, string $actorId): array
    {
        $merchant = $this->findMerchantOrFail($merchantId);
        $serviceIds = array_map('intval', $serviceIds);
        $now = now();

        DB::connection('mysuncash')->transaction(function () use ($merchant, $serviceIds, $actorId, $now) {
            $existing = ServicesPermission::where('client_record_id', $merchant->id)->get()->keyBy('system_services_id');

            foreach ($serviceIds as $serviceId) {
                if ($existing->has($serviceId)) {
                    $existing[$serviceId]->update(['status' => 'A', 'user_id_modify' => $actorId, 'modification_date' => $now]);
                } else {
                    ServicesPermission::create([
                        'client_record_id' => $merchant->id,
                        'system_services_id' => $serviceId,
                        'status' => 'A',
                        'user_id_create' => $actorId,
                    ]);
                }
            }

            foreach ($existing as $serviceId => $permission) {
                if (! in_array($serviceId, $serviceIds, true)) {
                    $permission->update(['status' => 'I', 'user_id_modify' => $actorId, 'modification_date' => $now]);
                }
            }
        });

        return $this->listServices($merchant->id);
    }
}
