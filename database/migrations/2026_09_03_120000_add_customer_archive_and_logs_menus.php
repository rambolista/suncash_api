<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Legacy admin's "Customers > Archive", "Customer Logs", and "Customer
 * Failed Linking Logs" — filed under the existing "Customers" section
 * (menu id 293), right after "Bank Loads" to match legacy's own menu
 * order. Legacy gates the two log menus behind a hardcoded admin-username
 * whitelist (`settings::$admin_users_for_mng`); this system uses the
 * normal role/menu permission system instead.
 */
return new class extends Migration
{
    private const CUSTOMERS_MENU_ID = 293;

    public function up(): void
    {
        $now = now();

        $archiveMenuId = DB::table('menus')->insertGetId([
            'parent_id' => self::CUSTOMERS_MENU_ID,
            'label' => 'Archive',
            'slug' => 'pages:customers-archive',
            'url' => '/customers/archive',
            'icon' => 'archive',
            'sort_order' => 5,
            'is_title' => 0,
            'is_active' => 1,
            'is_disabled' => 0,
            'is_special' => 0,
            'tab_layout' => 'horizontal',
            'supports_view' => 1,
            'supports_add' => 0,
            'supports_edit' => 0,
            'supports_delete' => 1,
            'supports_approve' => 0,
            'supports_export' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('role_menu_permissions')->insert([
            'role_id' => 1,
            'menu_id' => $archiveMenuId,
            'can_view' => 1,
            'can_add' => 0,
            'can_edit' => 0,
            'can_delete' => 1,
            'can_approve' => 0,
            'can_execute' => 0,
            'can_cancel' => 0,
            'can_reverse' => 0,
            'can_export' => 1,
            'can_print' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $logsMenuId = DB::table('menus')->insertGetId([
            'parent_id' => self::CUSTOMERS_MENU_ID,
            'label' => 'Customer Logs',
            'slug' => 'pages:customers-logs',
            'url' => '/customers/logs',
            'icon' => 'file-text',
            'sort_order' => 6,
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

        DB::table('role_menu_permissions')->insert([
            'role_id' => 1,
            'menu_id' => $logsMenuId,
            'can_view' => 1,
            'can_add' => 0,
            'can_edit' => 0,
            'can_delete' => 0,
            'can_approve' => 0,
            'can_execute' => 0,
            'can_cancel' => 0,
            'can_reverse' => 0,
            'can_export' => 0,
            'can_print' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $failedLogsMenuId = DB::table('menus')->insertGetId([
            'parent_id' => self::CUSTOMERS_MENU_ID,
            'label' => 'Customer Failed Linking Logs',
            'slug' => 'pages:customers-failed-logs',
            'url' => '/customers/failed-logs',
            'icon' => 'file-x',
            'sort_order' => 7,
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

        DB::table('role_menu_permissions')->insert([
            'role_id' => 1,
            'menu_id' => $failedLogsMenuId,
            'can_view' => 1,
            'can_add' => 0,
            'can_edit' => 0,
            'can_delete' => 0,
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

    public function down(): void
    {
        foreach (['pages:customers-archive', 'pages:customers-logs', 'pages:customers-failed-logs'] as $slug) {
            $menuId = DB::table('menus')->where('slug', $slug)->value('id');
            DB::table('role_menu_permissions')->where('menu_id', $menuId)->delete();
            DB::table('menus')->where('id', $menuId)->delete();
        }
    }
};
