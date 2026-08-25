<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Merchant Details" duplicated the existing edit-pencil button (same
 * wizard, same data), and "Password" is a one-shot confirm-and-reset
 * action like Deactivate — neither belongs in the Manage panel's tab
 * list. Both now live as direct icon buttons in the merchant list's
 * Action column instead.
 */
return new class extends Migration
{
    private const REMOVED_KEYS = ['merchant-details', 'password'];

    public function up(): void
    {
        $menuId = DB::table('menus')->where('slug', 'pages:merchants-registration')->value('id');
        if (! $menuId) {
            return;
        }

        $tabIds = DB::table('menu_tabs')
            ->where('menu_id', $menuId)
            ->whereIn('key', self::REMOVED_KEYS)
            ->pluck('id');

        DB::table('role_menu_tab_permissions')->whereIn('menu_tab_id', $tabIds)->delete();
        DB::table('menu_tabs')->whereIn('id', $tabIds)->delete();
    }

    public function down(): void
    {
        $menuId = DB::table('menus')->where('slug', 'pages:merchants-registration')->value('id');
        if (! $menuId) {
            return;
        }

        $now = now();
        $tabs = [
            ['key' => 'merchant-details', 'label' => 'Merchant Details', 'icon' => 'building', 'sort_order' => -1],
            ['key' => 'password', 'label' => 'Password', 'icon' => 'key', 'sort_order' => 6],
        ];

        $rolesWithAccess = DB::table('role_menu_permissions')
            ->where('menu_id', $menuId)
            ->where('can_view', 1)
            ->get(['role_id', 'can_edit']);

        foreach ($tabs as $tab) {
            $tabId = DB::table('menu_tabs')->insertGetId([
                'menu_id' => $menuId,
                'key' => $tab['key'],
                'label' => $tab['label'],
                'icon' => $tab['icon'],
                'sort_order' => $tab['sort_order'],
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
            ]);

            foreach ($rolesWithAccess as $rolePermission) {
                DB::table('role_menu_tab_permissions')->updateOrInsert(
                    ['role_id' => $rolePermission->role_id, 'menu_tab_id' => $tabId],
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
};
