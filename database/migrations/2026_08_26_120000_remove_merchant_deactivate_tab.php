<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Deactivate" is a single confirm-and-toggle action, not a content tab —
 * it now lives as a direct button in the merchant list's Action column
 * instead of a tab inside the Manage Merchant view.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tabId = DB::table('menu_tabs')
            ->whereIn('menu_id', DB::table('menus')->where('slug', 'pages:merchants-registration')->pluck('id'))
            ->where('key', 'deactivate')
            ->value('id');

        if (! $tabId) {
            return;
        }

        DB::table('role_menu_tab_permissions')->where('menu_tab_id', $tabId)->delete();
        DB::table('menu_tabs')->where('id', $tabId)->delete();
    }

    public function down(): void
    {
        $menuId = DB::table('menus')->where('slug', 'pages:merchants-registration')->value('id');
        if (! $menuId) {
            return;
        }

        $now = now();
        $tabId = DB::table('menu_tabs')->insertGetId([
            'menu_id' => $menuId,
            'key' => 'deactivate',
            'label' => 'Deactivate',
            'icon' => 'ban',
            'sort_order' => 12,
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

        $rolesWithAccess = DB::table('role_menu_permissions')
            ->where('menu_id', $menuId)
            ->where('can_view', 1)
            ->get(['role_id', 'can_edit']);

        foreach ($rolesWithAccess as $rolePermission) {
            DB::table('role_menu_tab_permissions')->insert([
                'role_id' => $rolePermission->role_id,
                'menu_tab_id' => $tabId,
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
            ]);
        }
    }
};
