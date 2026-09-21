<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Merchants" was its own top-level section title with a single child
 * (Merchant Management). Nesting it under "Main" as an expandable
 * dropdown — same pattern as "Access Management" under
 * "Administration" — needs its own role_menu_permissions row (dropdown
 * parents are gated like any other menu), which title rows never had.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Resolved by slug, not hardcoded ids — an auto-increment id depends on
        // how many other menu rows exist before it, which varies across
        // environments (a fresh install's ids won't match this dev database's).
        $mainMenuId = DB::table('menus')->where('slug', 'main')->whereNull('parent_id')->value('id');
        $merchantsMenuId = DB::table('menus')->where('slug', 'merchants')->value('id');
        $merchantManagementMenuId = DB::table('menus')->where('slug', 'pages:merchants-registration')->value('id');

        DB::table('menus')
            ->where('id', $merchantsMenuId)
            ->update([
                'parent_id' => $mainMenuId,
                'is_title' => 0,
                'sort_order' => 1,
            ]);

        $rolePermissions = DB::table('role_menu_permissions')
            ->where('menu_id', $merchantManagementMenuId)
            ->get();

        $now = now();

        foreach ($rolePermissions as $permission) {
            DB::table('role_menu_permissions')->updateOrInsert(
                ['role_id' => $permission->role_id, 'menu_id' => $merchantsMenuId],
                [
                    'can_view' => $permission->can_view,
                    'can_add' => $permission->can_add,
                    'can_edit' => $permission->can_edit,
                    'can_delete' => $permission->can_delete,
                    'can_approve' => $permission->can_approve,
                    'can_execute' => $permission->can_execute,
                    'can_cancel' => $permission->can_cancel,
                    'can_reverse' => $permission->can_reverse,
                    'can_export' => $permission->can_export,
                    'can_print' => $permission->can_print,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            );
        }
    }

    public function down(): void
    {
        $merchantsMenuId = DB::table('menus')->where('slug', 'merchants')->value('id');

        DB::table('role_menu_permissions')->where('menu_id', $merchantsMenuId)->delete();

        DB::table('menus')
            ->where('id', $merchantsMenuId)
            ->update([
                'parent_id' => null,
                'is_title' => 1,
                'sort_order' => 4,
            ]);
    }
};
