<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $now = now();

            DB::table('menus')->updateOrInsert(
                ['slug' => 'customers'],
                [
                    'label' => 'Customers',
                    'url' => null,
                    'icon' => 'users-group',
                    'parent_id' => null,
                    'sort_order' => 3,
                    'is_title' => true,
                    'is_active' => true,
                    'is_disabled' => false,
                    'is_special' => false,
                    'badge_text' => null,
                    'badge_class' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );

            $customersTitleId = DB::table('menus')->where('slug', 'customers')->value('id');

            DB::table('menus')
                ->where('slug', 'pages:apps-customers')
                ->update([
                    'label' => 'Customer List',
                    'parent_id' => $customersTitleId,
                    'sort_order' => 0,
                    'updated_at' => $now,
                ]);

            $adminRoleId = DB::table('roles')->where('name', 'Admin')->value('id');
            $customerListId = DB::table('menus')->where('slug', 'pages:apps-customers')->value('id');

            if ($adminRoleId && $customerListId) {
                DB::table('role_menu_permissions')->updateOrInsert(
                    ['role_id' => $adminRoleId, 'menu_id' => $customerListId],
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
        DB::transaction(function (): void {
            $customerListId = DB::table('menus')->where('slug', 'pages:apps-customers')->value('id');

            if ($customerListId) {
                DB::table('role_menu_permissions')->where('menu_id', $customerListId)->delete();
                DB::table('menus')->where('id', $customerListId)->update([
                    'label' => 'Customers',
                    'parent_id' => null,
                    'sort_order' => 3,
                    'updated_at' => now(),
                ]);
            }

            DB::table('menus')->where('slug', 'customers')->delete();
        });
    }
};
