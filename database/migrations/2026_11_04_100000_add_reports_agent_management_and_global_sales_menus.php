<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds "Agent Management" (legacy `agent_reports`) and "Global Sales" (legacy `global_sales_report`, "Global Sales Report")
 * under the existing "Reports" menu: view each report and export it to PDF/Excel.
 */
return new class extends Migration
{
    private const MENUS = [
        ['label' => 'Agent Management', 'slug' => 'pages:reports-agent-management', 'url' => '/reports/agent-management', 'icon' => 'user-star', 'sort_order' => 11],
        ['label' => 'Global Sales', 'slug' => 'pages:reports-global-sales', 'url' => '/reports/global-sales', 'icon' => 'world', 'sort_order' => 12],
    ];

    public function up(): void
    {
        $reportsId = DB::table('menus')->where('slug', 'pages:reports')->value('id');
        if (! $reportsId) {
            return;
        }

        $now = now();

        foreach (self::MENUS as $menu) {
            $menuId = DB::table('menus')->insertGetId($menu + [
                'parent_id' => $reportsId,
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
                'role_id' => 1, 'menu_id' => $menuId,
                'can_view' => 1, 'can_add' => 0, 'can_edit' => 0, 'can_delete' => 0, 'can_approve' => 0,
                'can_execute' => 0, 'can_cancel' => 0, 'can_reverse' => 0, 'can_export' => 1, 'can_print' => 0,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        foreach (self::MENUS as $menu) {
            $menuId = DB::table('menus')->where('slug', $menu['slug'])->value('id');
            if ($menuId) {
                DB::table('role_menu_permissions')->where('menu_id', $menuId)->delete();
                DB::table('menus')->where('id', $menuId)->delete();
            }
        }
    }
};
