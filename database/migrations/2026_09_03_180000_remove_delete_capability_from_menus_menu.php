<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Menus can now only be added and edited from the admin UI, never deleted
 * (the `destroy()` route/controller method were removed entirely). Turns off
 * the "Delete" capability on the "Menus" menu row itself so it stops
 * appearing as an assignable checkbox in Roles, and revokes any existing
 * can_delete grants for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $menuId = DB::table('menus')->where('slug', 'pages:apps-access-management-menus')->value('id');
        if (! $menuId) {
            return;
        }

        DB::table('menus')->where('id', $menuId)->update(['supports_delete' => 0]);
        DB::table('role_menu_permissions')->where('menu_id', $menuId)->update(['can_delete' => 0]);
    }

    public function down(): void
    {
        $menuId = DB::table('menus')->where('slug', 'pages:apps-access-management-menus')->value('id');
        if (! $menuId) {
            return;
        }

        DB::table('menus')->where('id', $menuId)->update(['supports_delete' => 1]);
    }
};
