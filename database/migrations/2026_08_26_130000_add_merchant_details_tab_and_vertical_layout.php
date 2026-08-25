<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds a "Merchant Details" tab (the business/fees/settlement/delivery/
 * alerts/other info previously only reachable via the separate edit
 * wizard) to the Manage Merchant tab list, and switches that menu to the
 * vertical tab layout — 14 tabs is too many to fit legibly in a single
 * horizontal strip.
 */
return new class extends Migration
{
    public function up(): void
    {
        $menuId = DB::table('menus')->where('slug', 'pages:merchants-registration')->value('id');
        if (! $menuId) {
            return;
        }

        DB::table('menus')->where('id', $menuId)->update(['tab_layout' => 'vertical', 'updated_at' => now()]);

        if (DB::table('menu_tabs')->where('menu_id', $menuId)->where('key', 'merchant-details')->exists()) {
            return;
        }

        DB::table('menu_tabs')->where('menu_id', $menuId)->increment('sort_order');

        $now = now();
        $tabId = DB::table('menu_tabs')->insertGetId([
            'menu_id' => $menuId,
            'key' => 'merchant-details',
            'label' => 'Merchant Details',
            'icon' => 'building',
            'sort_order' => 0,
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

    public function down(): void
    {
        $menuId = DB::table('menus')->where('slug', 'pages:merchants-registration')->value('id');
        if (! $menuId) {
            return;
        }

        DB::table('menus')->where('id', $menuId)->update(['tab_layout' => 'horizontal', 'updated_at' => now()]);

        $tabId = DB::table('menu_tabs')->where('menu_id', $menuId)->where('key', 'merchant-details')->value('id');
        if ($tabId) {
            DB::table('role_menu_tab_permissions')->where('menu_tab_id', $tabId)->delete();
            DB::table('menu_tabs')->where('id', $tabId)->delete();
            DB::table('menu_tabs')->where('menu_id', $menuId)->decrement('sort_order');
        }
    }
};
