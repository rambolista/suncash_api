<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Legacy admin's "Tools" sidebar section (`menu.php:316-371`) is a ~25-item
 * catch-all; only the first two items — Transaction Fees and Transaction
 * Limits — are ported here as a new top-level "Tools" section, inserted
 * right before "Promotions" (its current sort_order), which shifts down by
 * one. The rest of legacy's Tools items are separate, unrequested features.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $mainMenuId = DB::table('menus')->where('slug', 'main')->whereNull('parent_id')->value('id');

        DB::table('menus')->where('parent_id', $mainMenuId)->where('sort_order', '>=', 8)->increment('sort_order');

        $sectionId = DB::table('menus')->insertGetId([
            'parent_id' => $mainMenuId,
            'label' => 'Tools',
            'slug' => 'pages:tools',
            'url' => null,
            'icon' => 'tool',
            'sort_order' => 8,
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

        $children = [
            ['label' => 'Transaction Fees', 'slug' => 'pages:tools-transaction-fees', 'url' => '/tools/transaction-fees', 'icon' => 'receipt-2', 'sort_order' => 0],
            ['label' => 'Transaction Limits', 'slug' => 'pages:tools-transaction-limits', 'url' => '/tools/transaction-limits', 'icon' => 'gauge', 'sort_order' => 1],
        ];

        foreach ($children as $child) {
            $menuId = DB::table('menus')->insertGetId(array_merge($child, [
                'parent_id' => $sectionId,
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
        $mainMenuId = DB::table('menus')->where('slug', 'main')->whereNull('parent_id')->value('id');

        $menuIds = DB::table('menus')->whereIn('slug', ['pages:tools', 'pages:tools-transaction-fees', 'pages:tools-transaction-limits'])->pluck('id');
        DB::table('role_menu_permissions')->whereIn('menu_id', $menuIds)->delete();
        DB::table('menus')->whereIn('id', $menuIds)->delete();

        DB::table('menus')->where('parent_id', $mainMenuId)->where('sort_order', '>', 8)->decrement('sort_order');
    }
};
