<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::transaction(function () use ($now): void {
            DB::table('menus')->updateOrInsert(
                ['slug' => 'pages:apps-customers'],
                [
                    'label' => 'Customers',
                    'url' => '/apps/customers',
                    'icon' => 'users-group',
                    'parent_id' => DB::table('menus')->where('slug', 'apps')->value('id'),
                    'sort_order' => 3,
                    'is_title' => false,
                    'is_active' => true,
                    'is_disabled' => false,
                    'is_special' => false,
                    'badge_text' => null,
                    'badge_class' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

            $adminRoleId = DB::table('roles')->where('name', 'Admin')->value('id');
            $menuId = DB::table('menus')->where('slug', 'pages:apps-customers')->value('id');

            if ($adminRoleId && $menuId) {
                DB::table('role_menu_permissions')->updateOrInsert(
                    ['role_id' => $adminRoleId, 'menu_id' => $menuId],
                    [
                        'can_view' => true,
                        'can_add' => true,
                        'can_edit' => true,
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
        $menuId = DB::table('menus')->where('slug', 'pages:apps-customers')->value('id');

        if ($menuId) {
            DB::table('role_menu_permissions')->where('menu_id', $menuId)->delete();
            DB::table('menus')->where('id', $menuId)->delete();
        }
    }
};
