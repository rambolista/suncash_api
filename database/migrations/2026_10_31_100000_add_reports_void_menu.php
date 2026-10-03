<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds "Void" under Reports: legacy `voids_reports/index/void`, whose "List of Reports" dropdown (Voided Sales,
 * Voids by Product, Number of Voids) becomes tabs — access is granted per tab, as on Kiosk > Reports.
 */
return new class extends Migration
{
    private const TABS = [
        ['key' => 'voided_sales', 'label' => 'Voided Sales Report', 'icon' => 'receipt-refund'],
        ['key' => 'voids_by_product', 'label' => 'Voids by Product', 'icon' => 'category'],
        ['key' => 'number_of_voids', 'label' => 'Number of Voids', 'icon' => 'list-numbers'],
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
            'label' => 'Void',
            'slug' => 'pages:reports-void',
            'url' => '/reports/void',
            'icon' => 'ban',
            'sort_order' => 8,
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
        $menuId = DB::table('menus')->where('slug', 'pages:reports-void')->value('id');
        if (! $menuId) {
            return;
        }

        DB::table('role_menu_tab_permissions')->whereIn('menu_tab_id', DB::table('menu_tabs')->where('menu_id', $menuId)->pluck('id'))->delete();
        DB::table('menu_tabs')->where('menu_id', $menuId)->delete();
        DB::table('role_menu_permissions')->where('menu_id', $menuId)->delete();
        DB::table('menus')->where('id', $menuId)->delete();
    }
};
