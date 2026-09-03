<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Adds the "Cash Exposure" tab (7th) to the Kiosk "Reports" tabbed page. */
return new class extends Migration
{
    public function up(): void
    {
        $menuId = DB::table('menus')->where('slug', 'pages:kiosk-reports')->value('id');
        if (! $menuId) {
            return;
        }

        $now = now();
        DB::table('menu_tabs')->updateOrInsert(
            ['menu_id' => $menuId, 'key' => 'cash_exposure'],
            [
                'label' => 'Cash Exposure',
                'icon' => 'alert-triangle',
                'sort_order' => 6,
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

        $tabId = DB::table('menu_tabs')->where('menu_id', $menuId)->where('key', 'cash_exposure')->value('id');

        // Backfill: any role that already has can_view on the parent "Reports" menu
        // gets the same can_view/can_export mirrored onto the new tab, matching
        // how the previous tabs were backfilled when they were introduced.
        DB::table('role_menu_permissions')
            ->where('menu_id', $menuId)
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
    }

    public function down(): void
    {
        $menuId = DB::table('menus')->where('slug', 'pages:kiosk-reports')->value('id');
        if (! $menuId) {
            return;
        }

        $tabId = DB::table('menu_tabs')->where('menu_id', $menuId)->where('key', 'cash_exposure')->value('id');
        if ($tabId) {
            DB::table('role_menu_tab_permissions')->where('menu_tab_id', $tabId)->delete();
            DB::table('menu_tabs')->where('id', $tabId)->delete();
        }
    }
};
