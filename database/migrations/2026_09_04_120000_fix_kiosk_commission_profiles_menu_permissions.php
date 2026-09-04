<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The previous migration (2026_09_04_110000) backfilled permissions for
 * "Kiosk > Commission Profiles" onto any role with `can_view` on the
 * *parent* "Kiosk" menu row itself. In practice no role has that flag set
 * on the parent row — every role's actual Kiosk access lives on the child
 * menu rows (Users, Reports, etc.) — so that backfill matched zero roles
 * and the new menu item was invisible to everyone, including Admin.
 *
 * This backfills instead from any role that can already view an existing
 * Kiosk child menu, which is how real Kiosk access is actually granted.
 */
return new class extends Migration
{
    public function up(): void
    {
        $kioskMenuId = DB::table('menus')->where('slug', 'pages:kiosk')->value('id');
        $menuId = DB::table('menus')->where('slug', 'pages:kiosk-commission-profiles')->value('id');
        if (! $kioskMenuId || ! $menuId) {
            return;
        }

        $now = now();
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
                        'can_add' => 1,
                        'can_edit' => 1,
                        'can_delete' => 1,
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
        // No-op: reverting to the previous (broken) backfill state isn't useful.
    }
};
