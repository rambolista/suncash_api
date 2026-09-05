<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Standalone "Kiosk > Product Profiles" menu item, positioned right after
 * "Commission Profiles" (and before "Reports", bumped from sort_order 5 to 6).
 *
 * Permission backfill is based on which roles can already view a SIBLING
 * Kiosk child menu (not the parent "Kiosk" menu row itself) — see
 * 2026_09_04_120000_fix_kiosk_commission_profiles_menu_permissions.php for
 * why: no role has `can_view` set on the parent row, so gating the backfill
 * on that condition (as the original Commission Profiles migration
 * mistakenly did) matches zero roles.
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

        DB::table('menus')->where('parent_id', $kioskMenuId)->where('sort_order', '>=', 5)->increment('sort_order');

        $menuId = DB::table('menus')->insertGetId([
            'label' => 'Product Profiles',
            'slug' => 'pages:kiosk-product-profiles',
            'url' => '/kiosk/product-profiles',
            'icon' => 'package',
            'parent_id' => $kioskMenuId,
            'sort_order' => 5,
            'is_title' => 0,
            'is_active' => 1,
            'is_disabled' => 0,
            'is_special' => 0,
            'tab_layout' => 'horizontal',
            'supports_view' => 1,
            'supports_add' => 1,
            'supports_edit' => 1,
            'supports_delete' => 0,
            'supports_approve' => 0,
            'supports_execute' => 1,
            'supports_cancel' => 0,
            'supports_reverse' => 0,
            'supports_export' => 0,
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
                        'can_add' => 1,
                        'can_edit' => 1,
                        'can_delete' => 0,
                        'can_approve' => 0,
                        'can_execute' => 1,
                        'can_cancel' => 0,
                        'can_reverse' => 0,
                        'can_export' => 0,
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
        $menuId = DB::table('menus')->where('slug', 'pages:kiosk-product-profiles')->value('id');
        if ($menuId) {
            DB::table('role_menu_permissions')->where('menu_id', $menuId)->delete();
            DB::table('menus')->where('id', $menuId)->delete();
        }
        if ($kioskMenuId) {
            DB::table('menus')->where('parent_id', $kioskMenuId)->where('sort_order', '>', 5)->decrement('sort_order');
        }
    }
};
