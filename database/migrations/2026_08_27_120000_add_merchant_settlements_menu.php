<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Legacy admin's "Merchant Settlements" (administrator/manual_settlement) —
 * the approval queue for merchant withdrawal requests, sibling to Business/
 * Charity Management under "Merchants". Needs `can_approve` for the
 * process/reject actions in addition to the usual CRUD rights.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // Resolved by slug, not a hardcoded id — see the note in
        // 2026_08_27_110000_nest_merchants_menu_under_main.php.
        $merchantsMenuId = DB::table('menus')->where('slug', 'merchants')->value('id');

        $menuId = DB::table('menus')->insertGetId([
            'label' => 'Merchant Settlements',
            'slug' => 'pages:merchants-settlements',
            'url' => '/merchants/settlements',
            'icon' => 'building-bank',
            'sort_order' => 3,
            'parent_id' => $merchantsMenuId,
            'is_title' => 0,
            'is_active' => 1,
            'is_disabled' => 0,
            'is_special' => 0,
            'tab_layout' => 'horizontal',
            'supports_view' => 1,
            'supports_add' => 0,
            'supports_edit' => 1,
            'supports_delete' => 0,
            'supports_approve' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('role_menu_permissions')->insert([
            'role_id' => 1,
            'menu_id' => $menuId,
            'can_view' => 1,
            'can_add' => 0,
            'can_edit' => 1,
            'can_delete' => 0,
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

    public function down(): void
    {
        $menuId = DB::table('menus')->where('slug', 'pages:merchants-settlements')->value('id');

        DB::table('role_menu_permissions')->where('menu_id', $menuId)->delete();
        DB::table('menus')->where('id', $menuId)->delete();
    }
};
