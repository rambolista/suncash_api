<?php

namespace App\Services\Kiosk;

use App\Mail\KioskUserPasswordResetMail;
use App\Models\Mysuncash\KioskBranch;
use App\Models\Mysuncash\KioskUser;
use App\Models\Mysuncash\UserAccount;
use App\Models\User;
use App\Services\LegacyCredentialCipher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * "Kiosk > Users" (legacy `administrator::report_users_list()` /
 * `kiosk_user_add()` / `kiosk_user_edit_v2()` / `kiosk_user_delete()` /
 * `kiosk_user_reset()`). One combined roster merging two distinct account
 * types that legacy itself array_merges into a single list:
 *
 * - "Kiosk" users (`kiosk_users`) — a physical-terminal login used by kiosk
 *   staff to authenticate on the terminal's own "Zout" cash-reconciliation
 *   screen (a separate legacy `services` app, not this codebase). Password
 *   is a plain MD5 hash, matching legacy exactly.
 * - "Admin" users (`user_account`, `role=3`) — an ordinary login to THIS
 *   admin backend, scoped to one kiosk branch via `tp_user_reference`.
 *   Password is AES-128-CBC + HMAC (`LegacyCredentialCipher`), matching the
 *   scheme already used for merchant portal logins on the same table.
 *
 * Legacy's Add form is already one shared view for both types (just a
 * hidden `is_admin_user` flag) — this port keeps that as one endpoint with
 * a `user_type` field, per explicit instruction. Edit similarly carries a
 * live type toggle that can promote a Kiosk user to Admin or demote an
 * Admin back to Kiosk; both are replicated (`update()` below), including
 * legacy's quirk of carrying the Kiosk user's MD5 hash into `user_account.
 * password` UNCHANGED on promote (no AES re-encryption) rather than the
 * plaintext round-trip a naive port might assume.
 *
 * Deliberately NOT ported: legacy's admin-creation flow also blasts a
 * notification e-mail to four hardcoded internal legacy staff addresses
 * (`accounts_model::register_admin_user()`) — those addresses belong to the
 * old organization's ops team and have no meaning here.
 */
class KioskUserService
{
    private function presentKiosk(KioskUser $user): array
    {
        return [
            'id' => $user->id,
            'user_type' => 'Kiosk',
            'branch_id' => $user->branch_id,
            'branch_name' => $user->branch?->name,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'username' => $user->username,
            'email_address' => $user->email_address,
        ];
    }

    private function presentAdmin(UserAccount $user): array
    {
        return [
            'id' => $user->id,
            'user_type' => 'Admin',
            'branch_id' => $user->tp_user_reference,
            'branch_name' => KioskBranch::find($user->tp_user_reference)?->name,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'username' => $user->user_name,
            'email_address' => $user->email_address,
        ];
    }

    public function list(?int $branchId = null): array
    {
        $kioskQuery = KioskUser::with('branch')->where('status', KioskUser::STATUS_ACTIVE);
        if ($branchId) {
            $kioskQuery->where('branch_id', $branchId);
        }
        $kioskRows = $kioskQuery->orderByDesc('id')->get()->map(fn (KioskUser $u) => $this->presentKiosk($u));

        $adminQuery = UserAccount::where('role', 3)->where('user_type_id', 0)->where('user_status_id', 0);
        if ($branchId) {
            $adminQuery->where('tp_user_reference', $branchId);
        }
        $adminRows = $adminQuery->orderByDesc('id')->get()->map(fn (UserAccount $u) => $this->presentAdmin($u));

        return $kioskRows->concat($adminRows)->all();
    }

    public function listBranches(): array
    {
        return KioskBranch::where('status', KioskBranch::STATUS_ACTIVE)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->all();
    }

    private function validateShared(array $data): array
    {
        $errors = [];
        foreach (['first_name' => 'first name', 'last_name' => 'last name', 'username' => 'username'] as $field => $label) {
            if (! filled($data[$field] ?? null)) {
                $errors[$field] = ["Please enter a {$label}."];
            }
        }
        if (! filled($data['password'] ?? null) || (string) $data['password'] !== (string) ($data['confirm_password'] ?? '')) {
            $errors['confirm_password'] = ['Passwords do not match.'];
        }

        return $errors;
    }

    /**
     * @throws ValidationException
     */
    public function create(array $data, User $actor): array
    {
        $type = (string) ($data['user_type'] ?? 'kiosk');

        return $type === 'admin' ? $this->createAdmin($data, $actor) : $this->createKiosk($data, $actor);
    }

    /**
     * @throws ValidationException
     */
    private function createKiosk(array $data, User $actor): array
    {
        $errors = $this->validateShared($data);
        if (! filled($data['branch_id'] ?? null) || (int) $data['branch_id'] < 0) {
            $errors['branch_id'] = ['Please select a kiosk branch.'];
        }
        if (! filled($data['email_address'] ?? null)) {
            $errors['email_address'] = ['Please enter an email address.'];
        }
        if (filled($data['password'] ?? null) && strlen((string) $data['password']) < 4) {
            $errors['password'] = ['Password must contain at least 4 characters long.'];
        }
        if (! $errors) {
            $taken = KioskUser::where('status', KioskUser::STATUS_ACTIVE)
                ->where(fn ($q) => $q->where('username', $data['username'])->orWhere('email_address', $data['email_address']))
                ->exists();
            if ($taken) {
                $errors['username'] = ['This username or email is already in use as a Kiosk user.'];
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $user = KioskUser::create([
            'branch_id' => (int) $data['branch_id'],
            'admin_user_id' => -1,
            'first_name' => trim((string) $data['first_name']),
            'last_name' => trim((string) $data['last_name']),
            'username' => trim((string) $data['username']),
            'password' => md5((string) $data['password']),
            'email_address' => trim((string) $data['email_address']),
            'status' => KioskUser::STATUS_ACTIVE,
            'create_by' => $actor->name ?? $actor->email,
            'create_date' => now(),
        ]);

        return $this->presentKiosk($user->load('branch'));
    }

    /**
     * @throws ValidationException
     */
    private function createAdmin(array $data, User $actor): array
    {
        $errors = $this->validateShared($data);
        if (! filled($data['branch_id'] ?? null) || (int) $data['branch_id'] <= 0) {
            $errors['branch_id'] = ['Please select a kiosk branch.'];
        }
        if (! filled($data['email_address'] ?? null) || ! filter_var($data['email_address'], FILTER_VALIDATE_EMAIL)) {
            $errors['email_address'] = ['Please enter a valid email address.'];
        }
        $password = (string) ($data['password'] ?? '');
        if (! (strlen($password) >= 8 && preg_match('/[A-Z]/', $password) && preg_match('/[a-z]/', $password) && preg_match('/[0-9]/', $password))) {
            $errors['password'] = ['Password must contain at least 8 characters long, one uppercase letter, one lowercase letter, and one number.'];
        }
        if (! $errors && UserAccount::where('user_name', $data['username'])->exists()) {
            $errors['username'] = ['The username already exists as an Admin user.'];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $now = now();
        [$encryptedPassword, $userKey] = array_values(LegacyCredentialCipher::encrypt($password));

        $user = DB::connection('mysuncash')->transaction(function () use ($data, $actor, $now, $encryptedPassword, $userKey) {
            $user = UserAccount::create([
                'user_type_id' => 0,
                'user_reference' => 0,
                'first_name' => trim((string) $data['first_name']),
                'last_name' => trim((string) $data['last_name']),
                'user_name' => trim((string) $data['username']),
                'password' => $encryptedPassword,
                'user_status_id' => 0,
                'user_id_create' => $actor->id,
                'user_id_modified' => $actor->id,
                'require_pw_change' => 1,
                'email_address' => trim((string) $data['email_address']),
                'pw_expiration' => $now->copy()->addDays(30)->toDateString(),
                'creation_date' => $now,
                'modification_date' => $now,
                'role' => 3,
                'tp_user_reference' => (int) $data['branch_id'],
            ]);

            DB::connection('mysuncash')->table('user_keys')->insert([
                'user_id' => $user->id,
                'key' => $userKey,
                'channel' => 'admin',
            ]);

            return $user;
        });

        return $this->presentAdmin($user);
    }

    /**
     * @throws ValidationException
     */
    public function update(string $currentType, int $id, array $data, User $actor): array
    {
        $newType = (string) ($data['user_type'] ?? $currentType);

        if ($currentType === 'admin' && $newType === 'admin') {
            return $this->updateAdmin($id, $data, $actor);
        }
        if ($currentType === 'kiosk' && $newType === 'kiosk') {
            return $this->updateKiosk($id, $data, $actor);
        }
        if ($currentType === 'kiosk' && $newType === 'admin') {
            return $this->promoteToAdmin($id, $data, $actor);
        }

        return $this->demoteToKiosk($id, $data, $actor);
    }

    /**
     * @throws ValidationException
     */
    private function findKioskOrFail(int $id): KioskUser
    {
        $user = KioskUser::where('status', KioskUser::STATUS_ACTIVE)->find($id);
        if (! $user) {
            throw ValidationException::withMessages(['id' => ['This kiosk user was not found.']]);
        }

        return $user;
    }

    /**
     * @throws ValidationException
     */
    private function findAdminOrFail(int $id): UserAccount
    {
        $user = UserAccount::where('role', 3)->where('user_status_id', 0)->find($id);
        if (! $user) {
            throw ValidationException::withMessages(['id' => ['This admin user was not found.']]);
        }

        return $user;
    }

    private function profileErrors(array $data): array
    {
        $errors = [];
        foreach (['first_name' => 'first name', 'last_name' => 'last name', 'username' => 'username'] as $field => $label) {
            if (! filled($data[$field] ?? null)) {
                $errors[$field] = ["Please enter a {$label}."];
            }
        }
        if (! filled($data['branch_id'] ?? null)) {
            $errors['branch_id'] = ['Please select a kiosk branch.'];
        }

        return $errors;
    }

    /**
     * @throws ValidationException
     */
    private function updateKiosk(int $id, array $data, User $actor): array
    {
        $user = $this->findKioskOrFail($id);
        $errors = $this->profileErrors($data);
        if (! $errors) {
            $taken = KioskUser::where('status', KioskUser::STATUS_ACTIVE)
                ->where('id', '!=', $id)
                ->where(fn ($q) => $q->where('username', $data['username'])->orWhere('email_address', $data['email_address'] ?? ''))
                ->exists();
            if ($taken) {
                $errors['username'] = ['This username or email is already in use as a Kiosk user.'];
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $user->update([
            'branch_id' => (int) $data['branch_id'],
            'first_name' => trim((string) $data['first_name']),
            'last_name' => trim((string) $data['last_name']),
            'username' => trim((string) $data['username']),
            'email_address' => trim((string) ($data['email_address'] ?? '')),
            'update_by' => $actor->name ?? $actor->email,
            'update_date' => now(),
        ]);

        return $this->presentKiosk($user->load('branch'));
    }

    /**
     * @throws ValidationException
     */
    private function updateAdmin(int $id, array $data, User $actor): array
    {
        $user = $this->findAdminOrFail($id);
        $errors = $this->profileErrors($data);
        if (! $errors && UserAccount::where('user_name', $data['username'])->where('id', '!=', $id)->exists()) {
            $errors['username'] = ['The username already exists as an Admin user.'];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $user->update([
            'first_name' => trim((string) $data['first_name']),
            'last_name' => trim((string) $data['last_name']),
            'user_name' => trim((string) $data['username']),
            'email_address' => trim((string) ($data['email_address'] ?? '')),
            'tp_user_reference' => (int) $data['branch_id'],
            'user_id_modified' => $actor->id,
            'modification_date' => now(),
        ]);

        return $this->presentAdmin($user);
    }

    /**
     * Kiosk → Admin. Legacy carries the kiosk user's MD5 password hash
     * directly into `user_account.password`, deliberately skipping the AES
     * scheme (`register_kiosk_admin_user(..., $is_password_encrypted=true)`)
     * — replicated as-is, not "fixed" into a re-encryption/round-trip.
     *
     * @throws ValidationException
     */
    private function promoteToAdmin(int $id, array $data, User $actor): array
    {
        $kioskUser = $this->findKioskOrFail($id);
        $errors = $this->profileErrors($data);
        if ((int) ($data['branch_id'] ?? 0) <= 0) {
            $errors['branch_id'] = ['Please select a kiosk branch.'];
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $adminUser = DB::connection('mysuncash')->transaction(function () use ($kioskUser, $data, $actor) {
            if ($kioskUser->admin_user_id > 0) {
                $adminUser = UserAccount::find($kioskUser->admin_user_id);
                $adminUser->update([
                    'first_name' => trim((string) $data['first_name']),
                    'last_name' => trim((string) $data['last_name']),
                    'user_name' => trim((string) $data['username']),
                    'email_address' => trim((string) ($data['email_address'] ?? '')),
                    'tp_user_reference' => (int) $data['branch_id'],
                    'user_status_id' => 0,
                    'user_id_modified' => $actor->id,
                    'modification_date' => now(),
                ]);
            } else {
                $now = now();
                $adminUser = UserAccount::create([
                    'user_type_id' => 0,
                    'user_reference' => 0,
                    'first_name' => trim((string) $data['first_name']),
                    'last_name' => trim((string) $data['last_name']),
                    'user_name' => trim((string) $data['username']),
                    'password' => $kioskUser->password,
                    'user_status_id' => 0,
                    'user_id_create' => $actor->id,
                    'user_id_modified' => $actor->id,
                    'require_pw_change' => 0,
                    'email_address' => trim((string) ($data['email_address'] ?? '')),
                    'pw_expiration' => $now->copy()->addDays(30)->toDateString(),
                    'creation_date' => $now,
                    'modification_date' => $now,
                    'role' => 3,
                    'tp_user_reference' => (int) $data['branch_id'],
                ]);
            }

            $kioskUser->update([
                'status' => KioskUser::STATUS_DELETED,
                'admin_user_id' => $adminUser->id,
                'update_by' => $actor->name ?? $actor->email,
                'update_date' => now(),
            ]);

            return $adminUser;
        });

        return $this->presentAdmin($adminUser);
    }

    /**
     * Admin → Kiosk. If a previously-linked kiosk row exists, reactivate it;
     * otherwise decrypt the admin's AES password and create a fresh kiosk
     * row carrying `md5(plaintext)` — mirrors legacy's
     * `accounts_model::get_admin_user_password()` + `kiosk_model::add_kiosk_user()`.
     *
     * @throws ValidationException
     */
    private function demoteToKiosk(int $id, array $data, User $actor): array
    {
        $adminUser = $this->findAdminOrFail($id);
        $errors = $this->profileErrors($data);
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $kioskUser = DB::connection('mysuncash')->transaction(function () use ($adminUser, $data, $actor) {
            $linked = KioskUser::where('admin_user_id', $adminUser->id)->first();

            if ($linked) {
                $linked->update([
                    'status' => KioskUser::STATUS_ACTIVE,
                    'branch_id' => (int) $data['branch_id'],
                    'first_name' => trim((string) $data['first_name']),
                    'last_name' => trim((string) $data['last_name']),
                    'username' => trim((string) $data['username']),
                    'email_address' => trim((string) ($data['email_address'] ?? '')),
                    'update_by' => $actor->name ?? $actor->email,
                    'update_date' => now(),
                ]);
                $kioskUser = $linked;
            } else {
                $userKey = DB::connection('mysuncash')->table('user_keys')
                    ->where('user_id', $adminUser->id)->where('channel', 'admin')->value('key');
                $plaintext = $userKey ? LegacyCredentialCipher::decrypt($adminUser->password, $userKey) : null;

                $kioskUser = KioskUser::create([
                    'branch_id' => (int) $data['branch_id'],
                    'admin_user_id' => $adminUser->id,
                    'first_name' => trim((string) $data['first_name']),
                    'last_name' => trim((string) $data['last_name']),
                    'username' => trim((string) $data['username']),
                    'password' => $plaintext !== null ? md5($plaintext) : $adminUser->password,
                    'email_address' => trim((string) ($data['email_address'] ?? '')),
                    'status' => KioskUser::STATUS_ACTIVE,
                    'create_by' => $actor->name ?? $actor->email,
                    'create_date' => now(),
                ]);
            }

            $adminUser->update([
                'user_status_id' => 1,
                'user_id_modified' => $actor->id,
                'modification_date' => now(),
            ]);

            return $kioskUser;
        });

        return $this->presentKiosk($kioskUser->load('branch'));
    }

    /**
     * Kiosk-user only — legacy renders no Delete link for Admin rows.
     *
     * @throws ValidationException
     */
    public function delete(int $id, User $actor): void
    {
        $user = $this->findKioskOrFail($id);

        $user->update([
            'status' => KioskUser::STATUS_DELETED,
            'update_by' => $actor->name ?? $actor->email,
            'update_date' => now(),
        ]);
    }

    /**
     * Kiosk-user only — legacy renders no Reset link for Admin rows.
     * Generates a random password (matching legacy's exact scheme) and
     * e-mails it; requires the user to have an e-mail address on file.
     *
     * @throws ValidationException
     */
    public function resetPassword(int $id, User $actor): array
    {
        $user = $this->findKioskOrFail($id);
        if (! filled($user->email_address)) {
            throw ValidationException::withMessages(['id' => ['Email address is not set.']]);
        }

        $newPassword = strtoupper(Str::random(6)).Str::random(2);

        $user->update([
            'password' => md5($newPassword),
            'update_by' => $actor->name ?? $actor->email,
            'update_date' => now(),
        ]);

        Mail::to($user->email_address)->send(new KioskUserPasswordResetMail($user->username, $newPassword));

        return ['username' => $user->username, 'email' => $user->email_address];
    }
}
