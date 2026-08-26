<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Legacy admin's "Float Management" sidebar section — Main Reserve Account,
 * Store Float Replenishments, Current Store Float Amounts, and Set Main
 * Reserve Account. Legacy gated this behind the generic admin session check
 * only (no feature-specific allow-list); this rewrite uses the same
 * role_menu_permissions RBAC as every other menu instead, with `can_approve`
 * enabled on the two approval-workflow screens.
 *
 * Added as a dropdown parent under "Main" (same pattern as "Promotions").
 */
return new class extends Migration
{
    private const MAIN_MENU_ID = 1;

    public function up(): void
    {
        $now = now();

        $floatManagementId = DB::table('menus')->insertGetId([
            'label' => 'Float Management',
            'slug' => 'pages:float-management',
            'url' => null,
            'icon' => 'building-bank',
            'parent_id' => self::MAIN_MENU_ID,
            'sort_order' => 3,
            'is_title' => 0,
            'is_active' => 1,
            'is_disabled' => 0,
            'is_special' => 0,
            'tab_layout' => 'horizontal',
            'supports_view' => 1,
            'supports_add' => 1,
            'supports_edit' => 1,
            'supports_delete' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $children = [
            ['label' => 'Main Reserve Account', 'slug' => 'pages:float-management-main-reserve-account', 'url' => '/float-management/main-reserve-account', 'icon' => 'building-bank', 'sort_order' => 0, 'supports_approve' => 1],
            ['label' => 'Store Float Replenishments', 'slug' => 'pages:float-management-store-float-replenishments', 'url' => '/float-management/store-float-replenishments', 'icon' => 'refresh', 'sort_order' => 1, 'supports_approve' => 1],
            ['label' => 'Current Store Float Amounts', 'slug' => 'pages:float-management-current-store-float-amounts', 'url' => '/float-management/current-store-float-amounts', 'icon' => 'cash', 'sort_order' => 2, 'supports_approve' => 0],
            ['label' => 'Set Main Reserve Account', 'slug' => 'pages:float-management-set-main-reserve-account', 'url' => '/float-management/set-main-reserve-account', 'icon' => 'settings', 'sort_order' => 3, 'supports_approve' => 0],
        ];

        $menuIds = [$floatManagementId];

        foreach ($children as $child) {
            $menuIds[] = DB::table('menus')->insertGetId($child + [
                'parent_id' => $floatManagementId,
                'is_title' => 0,
                'is_active' => 1,
                'is_disabled' => 0,
                'is_special' => 0,
                'tab_layout' => 'horizontal',
                'supports_view' => 1,
                'supports_add' => 1,
                'supports_edit' => 1,
                'supports_delete' => 1,
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
            ->where('slug', 'pages:float-management')
            ->orWhereIn('slug', [
                'pages:float-management-main-reserve-account',
                'pages:float-management-store-float-replenishments',
                'pages:float-management-current-store-float-amounts',
                'pages:float-management-set-main-reserve-account',
            ])
            ->pluck('id');

        DB::table('role_menu_permissions')->whereIn('menu_id', $menuIds)->delete();
        DB::table('menus')->whereIn('id', $menuIds)->delete();
    }
};
