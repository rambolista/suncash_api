<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::transaction(function () use ($now): void {
            // 1. Merchants title
            DB::table('menus')->updateOrInsert(
                ['slug' => 'merchants'],
                [
                    'label'      => 'Merchants',
                    'url'        => null,
                    'icon'       => 'building-store',
                    'parent_id'  => null,
                    'sort_order' => 4,
                    'is_title'   => true,
                    'is_active'  => true,
                    'is_disabled' => false,
                    'is_special'  => false,
                    'badge_text'  => null,
                    'badge_class' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

            $merchantsTitleId = DB::table('menus')->where('slug', 'merchants')->value('id');

            // 2. Registration child menu
            DB::table('menus')->updateOrInsert(
                ['slug' => 'pages:merchants-registration'],
                [
                    'label'      => 'Registration',
                    'url'        => '/merchants/registration',
                    'icon'       => 'file-plus',
                    'parent_id'  => $merchantsTitleId,
                    'sort_order' => 0,
                    'is_title'   => false,
                    'is_active'  => true,
                    'is_disabled' => false,
                    'is_special'  => false,
                    'badge_text'  => null,
                    'badge_class' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

            // 3. Grant Admin role full permissions on the Registration menu
            $adminRoleId = DB::table('roles')->where('name', 'Admin')->value('id');
            $registrationMenuId = DB::table('menus')->where('slug', 'pages:merchants-registration')->value('id');

            if ($adminRoleId && $registrationMenuId) {
                DB::table('role_menu_permissions')->updateOrInsert(
                    ['role_id' => $adminRoleId, 'menu_id' => $registrationMenuId],
                    [
                        'can_view'   => true,
                        'can_add'    => true,
                        'can_edit'   => true,
                        'can_delete' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $registrationMenuId = DB::table('menus')->where('slug', 'pages:merchants-registration')->value('id');

            if ($registrationMenuId) {
                DB::table('role_menu_permissions')->where('menu_id', $registrationMenuId)->delete();
                DB::table('menus')->where('id', $registrationMenuId)->delete();
            }

            DB::table('menus')->where('slug', 'merchants')->delete();
        });
    }
};
