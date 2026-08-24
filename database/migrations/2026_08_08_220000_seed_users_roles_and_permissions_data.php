<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Data migration: seeds the current production snapshot of users, roles,
 * role_user (role assignments) and role_menu_permissions (per-role menu
 * CRUD permissions) into the database.
 *
 * This is safe to re-run: it uses upsert() so existing rows (matched by
 * primary/unique keys) are updated rather than duplicated.
 *
 * Raw data lives alongside this migration in database/migrations/data/
 * so this file stays readable.
 */
return new class extends Migration
{
    public function up(): void
    {
        $dataPath = __DIR__ . '/data/';

        // 1. Users (must exist before role_user references them)
        if (Schema::hasTable('users')) {
            $users = require $dataPath . '2026_08_08_users_data.php';

            DB::table('users')->upsert(
                $users,
                ['id'],
                [
                    'name', 'first_name', 'middle_name', 'last_name', 'email',
                    'mobile_number', 'address', 'avatar_path', 'status',
                    'email_verified_at', 'password', 'remember_token',
                    'created_at', 'updated_at',
                ]
            );
        }

        // 2. Roles
        if (Schema::hasTable('roles')) {
            $roles = require $dataPath . '2026_08_08_roles_data.php';

            DB::table('roles')->upsert(
                $roles,
                ['id'],
                ['name', 'description', 'key_responsibilities', 'icon', 'created_at', 'updated_at']
            );
        }

        // 3. role_user pivot (which roles each user has)
        if (Schema::hasTable('role_user')) {
            $roleUser = require $dataPath . '2026_08_08_role_user_data.php';

            DB::table('role_user')->upsert(
                $roleUser,
                ['user_id', 'role_id'],
                ['user_id', 'role_id']
            );
        }

        // 4. role_menu_permissions (per-role, per-menu CRUD permissions)
        if (Schema::hasTable('role_menu_permissions')) {
            $permissions = require $dataPath . '2026_08_08_role_menu_permissions_data.php';

            foreach (array_chunk($permissions, 200) as $chunk) {
                DB::table('role_menu_permissions')->upsert(
                    $chunk,
                    ['id'],
                    ['role_id', 'menu_id', 'can_view', 'can_add', 'can_edit', 'can_delete', 'created_at', 'updated_at']
                );
            }
        }
    }

    public function down(): void
    {
        $dataPath = __DIR__ . '/data/';

        if (Schema::hasTable('role_menu_permissions')) {
            $permissions = require $dataPath . '2026_08_08_role_menu_permissions_data.php';
            DB::table('role_menu_permissions')->whereIn('id', array_column($permissions, 'id'))->delete();
        }

        if (Schema::hasTable('role_user')) {
            $roleUser = require $dataPath . '2026_08_08_role_user_data.php';
            foreach ($roleUser as $pair) {
                DB::table('role_user')
                    ->where('user_id', $pair['user_id'])
                    ->where('role_id', $pair['role_id'])
                    ->delete();
            }
        }

        if (Schema::hasTable('roles')) {
            $roles = require $dataPath . '2026_08_08_roles_data.php';
            DB::table('roles')->whereIn('id', array_column($roles, 'id'))->delete();
        }

        if (Schema::hasTable('users')) {
            $users = require $dataPath . '2026_08_08_users_data.php';
            DB::table('users')->whereIn('id', array_column($users, 'id'))->delete();
        }
    }
};
