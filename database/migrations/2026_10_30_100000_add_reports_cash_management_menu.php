<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds "Cash Management" under Reports: legacy `cashmgnt_reports/index/cashmgnt`, whose "List of Reports"
 * dropdown (Sales by Product, Sales by Location) becomes tabs — access is granted per tab, as on Kiosk > Reports.
 */
return new class extends Migration
{
    private const TABS = [
        ['key' => 'sales_by_product', 'label' => 'Sales by Product', 'icon' => 'shopping-cart'],
        ['key' => 'sales_by_location', 'label' => 'Sales by Location', 'icon' => 'map-pin'],
    ];

    public function up(): void
    {
        $reportsId = DB::table('menus')->where('slug', 'pages:reports')->value('id');
        if (! $reportsId) {
            return;
        }

        $now = now();
        $flags = ['supports_add' => 0, 'supports_edit' => 0, 'supports_delete' => 0, 'supports_approve' => 0, 'supports_execute' => 0, 'supports_cancel' => 0, 'supports_reverse' => 0, 'supports_print' => 0];

        $menuId = DB::table('menus')->insertGetId([
            'parent_id' => $reportsId,
            'label' => 'Cash Management',
            'slug' => 'pages:reports-cash-management',
            'url' => '/reports/cash-management',
            'icon' => 'cash',
            'sort_order' => 7,
            'is_title' => 0,
            'is_active' => 1,
            'is_disabled' => 0,
            'is_special' => 0,
            'tab_layout' => 'horizontal',
            'supports_view' => 1,
            'supports_export' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ] + $flags);

        DB::table('role_menu_permissions')->insert([
            'role_id' => 1, 'menu_id' => $menuId,
            'can_view' => 1, 'can_add' => 0, 'can_edit' => 0, 'can_delete' => 0, 'can_approve' => 0,
            'can_execute' => 0, 'can_cancel' => 0, 'can_reverse' => 0, 'can_export' => 1, 'can_print' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        foreach (self::TABS as $index => $tab) {
            $tabId = DB::table('menu_tabs')->insertGetId([
                'menu_id' => $menuId, 'key' => $tab['key'], 'label' => $tab['label'], 'icon' => $tab['icon'],
                'sort_order' => $index, 'is_active' => 1, 'supports_view' => 1, 'supports_export' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ] + $flags);

            DB::table('role_menu_tab_permissions')->insert([
                'role_id' => 1, 'menu_tab_id' => $tabId,
                'can_view' => 1, 'can_add' => 0, 'can_edit' => 0, 'can_delete' => 0, 'can_approve' => 0,
                'can_execute' => 0, 'can_cancel' => 0, 'can_reverse' => 0, 'can_export' => 1, 'can_print' => 0,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $menuId = DB::table('menus')->where('slug', 'pages:reports-cash-management')->value('id');
        if (! $menuId) {
            return;
        }

        DB::table('role_menu_tab_permissions')->whereIn('menu_tab_id', DB::table('menu_tabs')->where('menu_id', $menuId)->pluck('id'))->delete();
        DB::table('menu_tabs')->where('menu_id', $menuId)->delete();
        DB::table('role_menu_permissions')->where('menu_id', $menuId)->delete();
        DB::table('menus')->where('id', $menuId)->delete();
    }
};
