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
    private const MERCHANTS_MENU_ID = 295;

    private const MERCHANT_MANAGEMENT_MENU_ID = 296;

    private const MAIN_MENU_ID = 1;

    public function up(): void
    {
        DB::table('menus')
            ->where('id', self::MERCHANTS_MENU_ID)
            ->update([
                'parent_id' => self::MAIN_MENU_ID,
                'is_title' => 0,
                'sort_order' => 1,
            ]);

        $rolePermissions = DB::table('role_menu_permissions')
            ->where('menu_id', self::MERCHANT_MANAGEMENT_MENU_ID)
            ->get();

        $now = now();

        foreach ($rolePermissions as $permission) {
            DB::table('role_menu_permissions')->updateOrInsert(
                ['role_id' => $permission->role_id, 'menu_id' => self::MERCHANTS_MENU_ID],
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
        DB::table('role_menu_permissions')->where('menu_id', self::MERCHANTS_MENU_ID)->delete();

        DB::table('menus')
            ->where('id', self::MERCHANTS_MENU_ID)
            ->update([
                'parent_id' => null,
                'is_title' => 1,
                'sort_order' => 4,
            ]);
    }
};
