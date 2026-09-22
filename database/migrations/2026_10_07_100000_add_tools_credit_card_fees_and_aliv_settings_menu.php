<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Tools > Credit Card Fees" and "Tools > Aliv Settings" (legacy
 * `tools/creditcard_fees` and `tools/aliv_settings`) — both plain view+edit
 * settings screens, added as two more children under the existing "Tools"
 * section (see 2026_09_20_160000), after the current last item (Bank
 * Accounts, sort_order 7).
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $toolsId = DB::table('menus')->where('slug', 'pages:tools')->value('id');

        $children = [
            ['label' => 'Credit Card Fees', 'slug' => 'pages:tools-credit-card-fees', 'url' => '/tools/credit-card-fees', 'icon' => 'credit-card', 'sort_order' => 8],
            ['label' => 'Aliv Settings', 'slug' => 'pages:tools-aliv-settings', 'url' => '/tools/aliv-settings', 'icon' => 'adjustments', 'sort_order' => 9],
        ];

        foreach ($children as $child) {
            $menuId = DB::table('menus')->insertGetId(array_merge($child, [
                'parent_id' => $toolsId,
                'is_title' => 0,
                'is_active' => 1,
                'is_disabled' => 0,
                'is_special' => 0,
                'tab_layout' => 'horizontal',
                'supports_view' => 1,
                'supports_add' => 0,
                'supports_edit' => 1,
                'supports_delete' => 0,
                'supports_approve' => 0,
                'supports_execute' => 0,
                'supports_cancel' => 0,
                'supports_reverse' => 0,
                'supports_export' => 0,
                'supports_print' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]));

            DB::table('role_menu_permissions')->insert([
                'role_id' => 1,
                'menu_id' => $menuId,
                'can_view' => 1,
                'can_add' => 0,
                'can_edit' => 1,
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
    }

    public function down(): void
    {
        $menuIds = DB::table('menus')->whereIn('slug', ['pages:tools-credit-card-fees', 'pages:tools-aliv-settings'])->pluck('id');
        DB::table('role_menu_permissions')->whereIn('menu_id', $menuIds)->delete();
        DB::table('menus')->whereIn('id', $menuIds)->delete();
    }
};
