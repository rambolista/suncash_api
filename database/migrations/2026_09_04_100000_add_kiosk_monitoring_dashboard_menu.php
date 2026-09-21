<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Legacy admin's "Kiosk" sidebar section (`fastpay::dashboard()`), first
 * item is "Monitoring Dashboard". The "Kiosk" section itself is created by
 * `2026_09_02_110000_ensure_kiosk_menu_section` (dated earlier so every
 * other Kiosk child migration can resolve it) — this just adds its first
 * child under it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $sectionId = DB::table('menus')->where('slug', 'pages:kiosk')->value('id');

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
        // Only this migration's own child — the "Kiosk" section itself is
        // owned by 2026_09_02_110000_ensure_kiosk_menu_section.
        $menuId = DB::table('menus')->where('slug', 'pages:kiosk-monitoring-dashboard')->value('id');
        DB::table('role_menu_permissions')->where('menu_id', $menuId)->delete();
        DB::table('menus')->where('id', $menuId)->delete();
    }
};
