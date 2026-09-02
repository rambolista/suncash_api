<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Legacy admin's "Kiosk > Zout Reports" (`administrator::kiosk_zout_reports()`)
 * — a read-only report of kiosk terminal cash-count/reconciliation
 * settlements. Added as the fifth item under "Kiosk", after "Users".
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $sectionId = DB::table('menus')->where('slug', 'pages:kiosk')->value('id');

        $menuId = DB::table('menus')->insertGetId([
            'parent_id' => $sectionId,
            'label' => 'Zout Reports',
            'slug' => 'pages:kiosk-zout-reports',
            'url' => '/kiosk/zout-reports',
            'icon' => 'report',
            'sort_order' => 4,
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
        $menuId = DB::table('menus')->where('slug', 'pages:kiosk-zout-reports')->value('id');
        DB::table('role_menu_permissions')->where('menu_id', $menuId)->delete();
        DB::table('menus')->where('id', $menuId)->delete();
    }
};
