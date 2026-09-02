<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Consolidates the standalone "Zout Reports" and "Replenish Reports" Kiosk
 * menu items into one "Reports" menu with a tabbed page (Zout / Replenish /
 * Transaction) — both features' routes and components are unchanged and
 * now render as tab panels of `/kiosk/reports` instead of their own pages.
 * "Cash Meters (Transaction)" and "Statement" are NOT part of this
 * consolidation yet; they stay standalone until migrated in a later pass.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $sectionId = DB::table('menus')->where('slug', 'pages:kiosk')->value('id');

        $oldMenuIds = DB::table('menus')->whereIn('slug', ['pages:kiosk-zout-reports', 'pages:kiosk-replenish-reports'])->pluck('id');
        DB::table('role_menu_permissions')->whereIn('menu_id', $oldMenuIds)->delete();
        DB::table('menus')->whereIn('id', $oldMenuIds)->delete();

        DB::table('menus')->where('slug', 'pages:kiosk-cash-meters')->update(['sort_order' => 4, 'updated_at' => $now]);

        $menuId = DB::table('menus')->insertGetId([
            'parent_id' => $sectionId,
            'label' => 'Reports',
            'slug' => 'pages:kiosk-reports',
            'url' => '/kiosk/reports',
            'icon' => 'report',
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
            'supports_execute' => 0,
            'supports_cancel' => 0,
            'supports_reverse' => 0,
            'supports_export' => 1,
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
            'can_execute' => 0,
            'can_cancel' => 0,
            'can_reverse' => 0,
            'can_export' => 1,
            'can_print' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $now = now();
        $sectionId = DB::table('menus')->where('slug', 'pages:kiosk')->value('id');

        $menuId = DB::table('menus')->where('slug', 'pages:kiosk-reports')->value('id');
        DB::table('role_menu_permissions')->where('menu_id', $menuId)->delete();
        DB::table('menus')->where('id', $menuId)->delete();

        DB::table('menus')->where('slug', 'pages:kiosk-cash-meters')->update(['sort_order' => 5, 'updated_at' => $now]);

        $zoutId = DB::table('menus')->insertGetId([
            'parent_id' => $sectionId, 'label' => 'Zout Reports', 'slug' => 'pages:kiosk-zout-reports',
            'url' => '/kiosk/zout-reports', 'icon' => 'report', 'sort_order' => 4,
            'is_title' => 0, 'is_active' => 1, 'is_disabled' => 0, 'is_special' => 0, 'tab_layout' => 'horizontal',
            'supports_view' => 1, 'supports_add' => 0, 'supports_edit' => 0, 'supports_delete' => 0,
            'supports_approve' => 0, 'supports_execute' => 0, 'supports_cancel' => 0, 'supports_reverse' => 0,
            'supports_export' => 1, 'supports_print' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $replenishId = DB::table('menus')->insertGetId([
            'parent_id' => $sectionId, 'label' => 'Replenish Reports', 'slug' => 'pages:kiosk-replenish-reports',
            'url' => '/kiosk/replenish-reports', 'icon' => 'cash-banknote', 'sort_order' => 6,
            'is_title' => 0, 'is_active' => 1, 'is_disabled' => 0, 'is_special' => 0, 'tab_layout' => 'horizontal',
            'supports_view' => 1, 'supports_add' => 0, 'supports_edit' => 0, 'supports_delete' => 0,
            'supports_approve' => 0, 'supports_execute' => 0, 'supports_cancel' => 0, 'supports_reverse' => 0,
            'supports_export' => 1, 'supports_print' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);

        foreach ([$zoutId, $replenishId] as $id) {
            DB::table('role_menu_permissions')->insert([
                'role_id' => 1, 'menu_id' => $id, 'can_view' => 1, 'can_add' => 0, 'can_edit' => 0, 'can_delete' => 0,
                'can_approve' => 0, 'can_execute' => 0, 'can_cancel' => 0, 'can_reverse' => 0,
                'can_export' => 1, 'can_print' => 0, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }
};
