<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Moves "Cash Meters (Transaction)" from its own standalone Kiosk menu item
 * into a "Cash Meters (Transactions)" tab on the "Kiosk > Reports" page,
 * positioned between Zout and Replenish — mirrors the original
 * Zout/Replenish consolidation (`2026_09_03_160000_consolidate_kiosk_reports_menu.php`).
 */
return new class extends Migration
{
    public function up(): void
    {
        $reportsMenuId = DB::table('menus')->where('slug', 'pages:kiosk-reports')->value('id');
        if (! $reportsMenuId) {
            return;
        }

        $now = now();

        // Shift every existing tab from `replenish` onward one slot to make room at sort_order=1.
        DB::table('menu_tabs')
            ->where('menu_id', $reportsMenuId)
            ->where('sort_order', '>=', 1)
            ->increment('sort_order');

        DB::table('menu_tabs')->updateOrInsert(
            ['menu_id' => $reportsMenuId, 'key' => 'cash_meters'],
            [
                'label' => 'Cash Meters (Transactions)',
                'icon' => 'cash',
                'sort_order' => 1,
                'is_active' => 1,
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
            ]
        );

        $tabId = DB::table('menu_tabs')->where('menu_id', $reportsMenuId)->where('key', 'cash_meters')->value('id');

        // Backfill: any role with can_view on the parent "Reports" menu gets
        // can_view/can_export mirrored onto the new tab, same as every other
        // tab's introduction this session.
        DB::table('role_menu_permissions')
            ->where('menu_id', $reportsMenuId)
            ->where('can_view', 1)
            ->get(['role_id', 'can_export'])
            ->each(function ($rolePermission) use ($tabId, $now) {
                DB::table('role_menu_tab_permissions')->updateOrInsert(
                    ['role_id' => $rolePermission->role_id, 'menu_tab_id' => $tabId],
                    [
                        'can_view' => 1,
                        'can_add' => 0,
                        'can_edit' => 0,
                        'can_delete' => 0,
                        'can_approve' => 0,
                        'can_execute' => 0,
                        'can_cancel' => 0,
                        'can_reverse' => 0,
                        'can_export' => (int) $rolePermission->can_export,
                        'can_print' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );
            });

        // Remove the old standalone menu entry + its role permissions.
        $oldMenuId = DB::table('menus')->where('slug', 'pages:kiosk-cash-meters')->value('id');
        if ($oldMenuId) {
            DB::table('role_menu_permissions')->where('menu_id', $oldMenuId)->delete();
            DB::table('menus')->where('id', $oldMenuId)->delete();
        }
    }

    public function down(): void
    {
        $reportsMenuId = DB::table('menus')->where('slug', 'pages:kiosk-reports')->value('id');
        if (! $reportsMenuId) {
            return;
        }

        $now = now();

        // Restore the standalone menu entry.
        $kioskMenuId = DB::table('menus')->where('slug', 'pages:kiosk')->value('id');
        DB::table('menus')->updateOrInsert(
            ['slug' => 'pages:kiosk-cash-meters'],
            [
                'label' => 'Cash Meters (Transaction)',
                'url' => '/kiosk/cash-meters',
                'icon' => 'cash',
                'parent_id' => $kioskMenuId,
                'sort_order' => 4,
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
            ]
        );

        $tabId = DB::table('menu_tabs')->where('menu_id', $reportsMenuId)->where('key', 'cash_meters')->value('id');
        if ($tabId) {
            DB::table('role_menu_tab_permissions')->where('menu_tab_id', $tabId)->delete();
            DB::table('menu_tabs')->where('id', $tabId)->delete();
        }

        DB::table('menu_tabs')
            ->where('menu_id', $reportsMenuId)
            ->where('sort_order', '>=', 1)
            ->decrement('sort_order');
    }
};
