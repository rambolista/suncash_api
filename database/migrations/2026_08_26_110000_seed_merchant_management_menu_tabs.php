<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TABS = [
        ['key' => 'principal-info', 'label' => 'Principal Info', 'icon' => 'user-star'],
        ['key' => 'prefund', 'label' => 'Merchant Prefund', 'icon' => 'wallet'],
        ['key' => 'auto-replenish', 'label' => 'Auto Replenish', 'icon' => 'refresh'],
        ['key' => 'branch', 'label' => 'Branch', 'icon' => 'building-store'],
        ['key' => 'terminals', 'label' => 'Terminals', 'icon' => 'device-desktop'],
        ['key' => 'password', 'label' => 'Password', 'icon' => 'key'],
        ['key' => 'user-management', 'label' => 'User Management', 'icon' => 'users'],
        ['key' => 'ezpay-access', 'label' => 'Ezpay Access', 'icon' => 'credit-card'],
        ['key' => 'services-permission', 'label' => 'Services Permission', 'icon' => 'apps'],
        ['key' => 'pos-users', 'label' => 'POS Users', 'icon' => 'user-cog'],
        ['key' => 'agent-commission', 'label' => 'Agent Commission Settings', 'icon' => 'percentage'],
        ['key' => 'float-account', 'label' => 'Store Float Account', 'icon' => 'building-bank'],
        ['key' => 'deactivate', 'label' => 'Deactivate', 'icon' => 'ban'],
    ];

    public function up(): void
    {
        $menuId = DB::table('menus')->where('slug', 'pages:merchants-registration')->value('id');
        if (! $menuId) {
            return;
        }

        $now = now();
        $tabIds = [];
        foreach (self::TABS as $index => $tab) {
            $tabIds[$tab['key']] = DB::table('menu_tabs')->updateOrInsert(
                ['menu_id' => $menuId, 'key' => $tab['key']],
                [
                    'label' => $tab['label'],
                    'icon' => $tab['icon'],
                    'sort_order' => $index,
                    'is_active' => 1,
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
                ]
            );
        }

        // Backfill: any role that already has can_view on the parent menu gets
        // the same can_view/can_edit mirrored onto every new tab, so existing
        // roles don't silently lose access when tabs are introduced.
        $tabRows = DB::table('menu_tabs')->where('menu_id', $menuId)->get(['id', 'key']);
        $rolesWithAccess = DB::table('role_menu_permissions')
            ->where('menu_id', $menuId)
            ->where('can_view', 1)
            ->get(['role_id', 'can_edit']);

        foreach ($rolesWithAccess as $rolePermission) {
            foreach ($tabRows as $tab) {
                DB::table('role_menu_tab_permissions')->updateOrInsert(
                    ['role_id' => $rolePermission->role_id, 'menu_tab_id' => $tab->id],
                    [
                        'can_view' => 1,
                        'can_edit' => (int) $rolePermission->can_edit,
                        'can_add' => 0,
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
            }
        }
    }

    public function down(): void
    {
        $menuId = DB::table('menus')->where('slug', 'pages:merchants-registration')->value('id');
        if (! $menuId) {
            return;
        }

        $tabIds = DB::table('menu_tabs')->where('menu_id', $menuId)->pluck('id');
        DB::table('role_menu_tab_permissions')->whereIn('menu_tab_id', $tabIds)->delete();
        DB::table('menu_tabs')->where('menu_id', $menuId)->delete();
    }
};
