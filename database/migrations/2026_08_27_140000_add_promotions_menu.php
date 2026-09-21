<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Legacy admin's "Promotions" sidebar section — Ticket Reports, Settings
 * (Cash + Physical Item prizes), and Sign Up Promotion (geo-fenced signup
 * bonus zones). Legacy gated this behind a hard-coded admin-username
 * whitelist (`settings::$xmas_promo_admin_users`); this rewrite uses the
 * same role_menu_permissions RBAC as every other menu instead.
 *
 * Added as a dropdown parent under "Main" (same pattern as "Merchants").
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // Resolved by slug, not a hardcoded id — see the note in
        // 2026_08_27_110000_nest_merchants_menu_under_main.php.
        $mainMenuId = DB::table('menus')->where('slug', 'main')->whereNull('parent_id')->value('id');

        $promotionsId = DB::table('menus')->insertGetId([
            'label' => 'Promotions',
            'slug' => 'pages:promotions',
            'url' => null,
            'icon' => 'gift',
            'parent_id' => $mainMenuId,
            'sort_order' => 2,
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
            ['label' => 'Ticket Reports', 'slug' => 'pages:promotions-ticket-reports', 'url' => '/promotions/ticket-reports', 'icon' => 'ticket', 'sort_order' => 0],
            ['label' => 'Settings', 'slug' => 'pages:promotions-settings', 'url' => '/promotions/settings', 'icon' => 'settings', 'sort_order' => 1],
            ['label' => 'Sign Up Promotion', 'slug' => 'pages:promotions-signup', 'url' => '/promotions/signup', 'icon' => 'map-pin', 'sort_order' => 2],
        ];

        $menuIds = [$promotionsId];

        foreach ($children as $child) {
            $menuIds[] = DB::table('menus')->insertGetId($child + [
                'parent_id' => $promotionsId,
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
                'can_approve' => 0,
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
            ->where('slug', 'pages:promotions')
            ->orWhereIn('slug', ['pages:promotions-ticket-reports', 'pages:promotions-settings', 'pages:promotions-signup'])
            ->pluck('id');

        DB::table('role_menu_permissions')->whereIn('menu_id', $menuIds)->delete();
        DB::table('menus')->whereIn('id', $menuIds)->delete();
    }
};
