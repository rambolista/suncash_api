<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Kiosk > Confirm Customer Service" menu item, positioned right after
 * "Cash Management" (bumping "Reports" from sort_order 9 to 10). Read-only
 * feature — only `can_view` is supported/granted. Permission backfill
 * mirrors 2026_09_16_110000_add_kiosk_cash_management_menu.php: granted to
 * every role that already has can_view on a sibling Kiosk menu.
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

        DB::table('menus')->where('parent_id', $kioskMenuId)->where('sort_order', '>=', 9)->increment('sort_order');

        $menuId = DB::table('menus')->insertGetId([
            'label' => 'Confirm Customer Service',
            'slug' => 'pages:kiosk-confirm-customer-service',
            'url' => '/kiosk/confirm-customer-service',
            'icon' => 'clipboard-search',
            'parent_id' => $kioskMenuId,
            'sort_order' => 9,
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
                        'can_approve' => 0,
                        'can_execute' => 0,
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
        $menuId = DB::table('menus')->where('slug', 'pages:kiosk-confirm-customer-service')->value('id');
        if ($menuId) {
            DB::table('role_menu_permissions')->where('menu_id', $menuId)->delete();
            DB::table('menus')->where('id', $menuId)->delete();
        }
        if ($kioskMenuId) {
            DB::table('menus')->where('parent_id', $kioskMenuId)->where('sort_order', '>', 9)->decrement('sort_order');
        }
    }
};
