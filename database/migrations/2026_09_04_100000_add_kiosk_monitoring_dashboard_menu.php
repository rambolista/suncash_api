<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Legacy admin's "Kiosk" sidebar section (`fastpay::dashboard()`), first
 * item is "Monitoring Dashboard". Inserted right after "Transactions"
 * (sort_order 4), so Float Management/Giftcards/Promotions shift down by
 * one, matching the same pattern used for Transactions itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $mainMenuId = DB::table('menus')->where('slug', 'main')->whereNull('parent_id')->value('id');

        DB::table('menus')->where('parent_id', $mainMenuId)->where('sort_order', '>=', 5)->increment('sort_order');

        $sectionId = DB::table('menus')->insertGetId([
            'parent_id' => $mainMenuId,
            'label' => 'Kiosk',
            'slug' => 'pages:kiosk',
            'url' => null,
            'icon' => 'device-desktop-analytics',
            'sort_order' => 5,
            'is_title' => 0,
            'is_active' => 1,
            'is_disabled' => 0,
            'is_special' => 0,
            'tab_layout' => 'horizontal',
            'supports_view' => 1,
            'supports_add' => 0,
            'supports_edit' => 0,
            'supports_delete' => 0,
            'supports_approve' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $menuId = DB::table('menus')->insertGetId([
            'parent_id' => $sectionId,
            'label' => 'Monitoring Dashboard',
            'slug' => 'pages:kiosk-monitoring-dashboard',
            'url' => '/kiosk/monitoring-dashboard',
            'icon' => 'device-desktop-analytics',
            'sort_order' => 0,
            'is_title' => 0,
            'is_active' => 1,
            'is_disabled' => 0,
            'is_special' => 0,
            'tab_layout' => 'horizontal',
            'supports_view' => 1,
            'supports_add' => 0,
            'supports_edit' => 0,
            'supports_delete' => 0,
            'supports_approve' => 0,
            'supports_execute' => 1,
            'supports_cancel' => 0,
            'supports_reverse' => 0,
            'supports_export' => 0,
            'supports_print' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('role_menu_permissions')->insert([
            'role_id' => 1,
            'menu_id' => $menuId,
            'can_view' => 1,
            'can_add' => 0,
            'can_edit' => 0,
            'can_delete' => 0,
            'can_approve' => 0,
            'can_execute' => 1,
            'can_cancel' => 0,
            'can_reverse' => 0,
            'can_export' => 0,
            'can_print' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $mainMenuId = DB::table('menus')->where('slug', 'main')->whereNull('parent_id')->value('id');

        $menuIds = DB::table('menus')->whereIn('slug', ['pages:kiosk', 'pages:kiosk-monitoring-dashboard'])->pluck('id');
        DB::table('role_menu_permissions')->whereIn('menu_id', $menuIds)->delete();
        DB::table('menus')->whereIn('id', $menuIds)->delete();

        DB::table('menus')->where('parent_id', $mainMenuId)->where('sort_order', '>', 5)->decrement('sort_order');
    }
};
