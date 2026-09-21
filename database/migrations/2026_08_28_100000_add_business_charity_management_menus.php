<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Legacy admin's "Business Management" (administrator/merchant_online_signup)
 * and "Charity Management" (administrator/charity_online_signup) — sibling
 * menus to the existing "Merchant Management" under "Merchants". Both are
 * pending/approved/rejected review queues over `clients` filtered by
 * merchant_type_id (1=Business, 3=Charity) rather than separate registration
 * flows, so they need `can_approve` in addition to the usual CRUD rights.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // Resolved by slug, not a hardcoded id — see the note in
        // 2026_08_27_110000_nest_merchants_menu_under_main.php.
        $merchantsMenuId = DB::table('menus')->where('slug', 'merchants')->value('id');

        $menus = [
            ['label' => 'Business Management', 'slug' => 'pages:merchants-business-management', 'url' => '/merchants/business-management', 'icon' => 'building-store', 'sort_order' => 1],
            ['label' => 'Charity Management', 'slug' => 'pages:merchants-charity-management', 'url' => '/merchants/charity-management', 'icon' => 'heart', 'sort_order' => 2],
        ];

        $menuIds = [];

        foreach ($menus as $menu) {
            $menuIds[] = DB::table('menus')->insertGetId($menu + [
                'parent_id' => $merchantsMenuId,
                'is_title' => 0,
                'is_active' => 1,
                'is_disabled' => 0,
                'is_special' => 0,
                'tab_layout' => 'horizontal',
                'supports_view' => 1,
                'supports_add' => 1,
                'supports_edit' => 1,
                'supports_delete' => 1,
                'supports_approve' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach ($menuIds as $menuId) {
            DB::table('role_menu_permissions')->insert([
                'role_id' => 1,
                'menu_id' => $menuId,
                'can_view' => 1,
                'can_add' => 1,
                'can_edit' => 1,
                'can_delete' => 1,
                'can_approve' => 1,
                'can_execute' => 0,
                'can_cancel' => 0,
                'can_reverse' => 0,
                'can_export' => 0,
                'can_print' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $menuIds = DB::table('menus')
            ->whereIn('slug', ['pages:merchants-business-management', 'pages:merchants-charity-management'])
            ->pluck('id');

        DB::table('role_menu_permissions')->whereIn('menu_id', $menuIds)->delete();
        DB::table('menus')->whereIn('id', $menuIds)->delete();
    }
};
