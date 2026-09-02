<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TABS = [
        ['key' => 'zout', 'label' => 'Zout', 'icon' => 'report'],
        ['key' => 'replenish', 'label' => 'Replenish', 'icon' => 'cash-banknote'],
        ['key' => 'transaction', 'label' => 'Transaction', 'icon' => 'report-money'],
    ];

    public function up(): void
    {
        $menuId = DB::table('menus')->where('slug', 'pages:kiosk-reports')->value('id');
        if (! $menuId) {
            return;
        }

        $now = now();
        foreach (self::TABS as $index => $tab) {
            DB::table('menu_tabs')->updateOrInsert(
                ['menu_id' => $menuId, 'key' => $tab['key']],
                [
                    'label' => $tab['label'],
                    'icon' => $tab['icon'],
                    'sort_order' => $index,
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
        }

        // Backfill: any role that already has can_view on the parent "Reports" menu
        // gets the same can_view/can_export mirrored onto every new tab, so existing
        // roles don't silently lose access to Zout/Replenish/Transaction when tabs
        // are introduced.
        $tabRows = DB::table('menu_tabs')->where('menu_id', $menuId)->get(['id', 'key']);
        $rolesWithAccess = DB::table('role_menu_permissions')
            ->where('menu_id', $menuId)
            ->where('can_view', 1)
            ->get(['role_id', 'can_export']);

        foreach ($rolesWithAccess as $rolePermission) {
            foreach ($tabRows as $tab) {
                DB::table('role_menu_tab_permissions')->updateOrInsert(
                    ['role_id' => $rolePermission->role_id, 'menu_tab_id' => $tab->id],
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
            }
        }
    }

    public function down(): void
    {
        $menuId = DB::table('menus')->where('slug', 'pages:kiosk-reports')->value('id');
        if (! $menuId) {
            return;
        }

        $tabIds = DB::table('menu_tabs')->where('menu_id', $menuId)->pluck('id');
        DB::table('role_menu_tab_permissions')->whereIn('menu_tab_id', $tabIds)->delete();
        DB::table('menu_tabs')->where('menu_id', $menuId)->delete();
    }
};
