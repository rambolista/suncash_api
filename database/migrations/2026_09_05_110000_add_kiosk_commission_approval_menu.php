<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Standalone "Kiosk > Commission Approval" menu item, positioned right
 * after "Product Profiles" (and before "Reports", bumped from sort_order 6
 * to 7). Permission backfill is based on which roles can already view a
 * SIBLING Kiosk child menu (not the parent "Kiosk" menu row itself) — see
 * 2026_09_04_120000_fix_kiosk_commission_profiles_menu_permissions.php for
 * why: no role has `can_view` set on the parent row.
 */
return new class extends Migration
{
    public function up(): void
    {
        $kioskMenuId = DB::table('menus')->where('slug', 'pages:kiosk')->value('id');
        if (! $kioskMenuId) {
            return;
        }

        $now = now();

        DB::table('menus')->where('parent_id', $kioskMenuId)->where('sort_order', '>=', 6)->increment('sort_order');

        $menuId = DB::table('menus')->insertGetId([
            'label' => 'Commission Approval',
            'slug' => 'pages:kiosk-commission-approval',
            'url' => '/kiosk/commission-approval',
            'icon' => 'clipboard-check',
            'parent_id' => $kioskMenuId,
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
            'supports_approve' => 1,
            'supports_execute' => 0,
            'supports_cancel' => 1,
            'supports_reverse' => 0,
            'supports_export' => 1,
            'supports_print' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $siblingIds = DB::table('menus')->where('parent_id', $kioskMenuId)->where('id', '!=', $menuId)->pluck('id');

        DB::table('role_menu_permissions')
            ->whereIn('menu_id', $siblingIds)
            ->where('can_view', 1)
            ->distinct()
            ->pluck('role_id')
            ->each(function ($roleId) use ($menuId, $now) {
                DB::table('role_menu_permissions')->updateOrInsert(
                    ['role_id' => $roleId, 'menu_id' => $menuId],
                    [
                        'can_view' => 1,
                        'can_add' => 0,
                        'can_edit' => 0,
                        'can_delete' => 0,
                        'can_approve' => 1,
                        'can_execute' => 0,
                        'can_cancel' => 1,
                        'can_reverse' => 0,
                        'can_export' => 1,
                        'can_print' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );
            });
    }

    public function down(): void
    {
        $kioskMenuId = DB::table('menus')->where('slug', 'pages:kiosk')->value('id');
        $menuId = DB::table('menus')->where('slug', 'pages:kiosk-commission-approval')->value('id');
        if ($menuId) {
            DB::table('role_menu_permissions')->where('menu_id', $menuId)->delete();
            DB::table('menus')->where('id', $menuId)->delete();
        }
        if ($kioskMenuId) {
            DB::table('menus')->where('parent_id', $kioskMenuId)->where('sort_order', '>', 6)->decrement('sort_order');
        }
    }
};
