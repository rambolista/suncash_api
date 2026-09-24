<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Adds a "Reports" section (right before "Tools") with "Money Transfer" (legacy `moneytransfer_reports`, renamed from "Money Transfer Reports" since it now lives inside "Reports"). */
return new class extends Migration
{
    public function up(): void
    {
        $mainId = DB::table('menus')->where('slug', 'main')->value('id');
        if (! $mainId) {
            return;
        }

        $toolsSortOrder = DB::table('menus')->where('slug', 'pages:tools')->value('sort_order');
        if ($toolsSortOrder === null) {
            return;
        }

        $now = now();

        DB::table('menus')->where('parent_id', $mainId)->where('sort_order', '>=', $toolsSortOrder)->increment('sort_order');

        $reportsId = DB::table('menus')->insertGetId([
            'parent_id' => $mainId,
            'label' => 'Reports',
            'slug' => 'pages:reports',
            'url' => null,
            'icon' => 'report',
            'sort_order' => $toolsSortOrder,
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
            'supports_export' => 0,
            'supports_print' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $moneyTransferId = DB::table('menus')->insertGetId([
            'parent_id' => $reportsId,
            'label' => 'Money Transfer',
            'slug' => 'pages:reports-money-transfer',
            'url' => '/reports/money-transfer',
            'icon' => 'transfer',
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
            'supports_execute' => 0,
            'supports_cancel' => 0,
            'supports_reverse' => 0,
            'supports_export' => 1,
            'supports_print' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('role_menu_permissions')->insert([
            [
                'role_id' => 1, 'menu_id' => $reportsId,
                'can_view' => 1, 'can_add' => 0, 'can_edit' => 0, 'can_delete' => 0, 'can_approve' => 0,
                'can_execute' => 0, 'can_cancel' => 0, 'can_reverse' => 0, 'can_export' => 0, 'can_print' => 0,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'role_id' => 1, 'menu_id' => $moneyTransferId,
                'can_view' => 1, 'can_add' => 0, 'can_edit' => 0, 'can_delete' => 0, 'can_approve' => 0,
                'can_execute' => 0, 'can_cancel' => 0, 'can_reverse' => 0, 'can_export' => 1, 'can_print' => 0,
                'created_at' => $now, 'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        $reportsId = DB::table('menus')->where('slug', 'pages:reports')->value('id');
        if (! $reportsId) {
            return;
        }

        $childIds = DB::table('menus')->where('parent_id', $reportsId)->pluck('id');
        DB::table('role_menu_permissions')->whereIn('menu_id', [...$childIds, $reportsId])->delete();
        DB::table('menus')->whereIn('id', $childIds)->delete();

        $mainId = DB::table('menus')->where('slug', 'main')->value('id');
        $reportsSortOrder = DB::table('menus')->where('id', $reportsId)->value('sort_order');
        DB::table('menus')->where('id', $reportsId)->delete();

        if ($mainId !== null && $reportsSortOrder !== null) {
            DB::table('menus')->where('parent_id', $mainId)->where('sort_order', '>', $reportsSortOrder)->decrement('sort_order');
        }
    }
};
