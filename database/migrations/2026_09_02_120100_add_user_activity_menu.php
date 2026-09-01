<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Administration > User Activity" — a new-system-only audit-trail screen
 * (no legacy equivalent), modeled after iBIMSKP's User Activity menu. Filed
 * under the existing "Administration" section (menu id 285), right after
 * "Settings".
 */
return new class extends Migration
{
    private const ADMINISTRATION_MENU_ID = 285;

    public function up(): void
    {
        $now = now();

        $menuId = DB::table('menus')->insertGetId([
            'parent_id' => self::ADMINISTRATION_MENU_ID,
            'label' => 'User Activity',
            'slug' => 'pages:administration-user-activity',
            'url' => '/administration/user-activity',
            'icon' => 'history',
            'sort_order' => 2,
            'is_title' => 0,
            'is_active' => 1,
            'is_disabled' => 0,
            'is_special' => 0,
            'tab_layout' => 'horizontal',
            'supports_view' => 1,
            'supports_add' => 0,
            'supports_edit' => 0,
            'supports_delete' => 0,
            'supports_approve' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('role_menu_permissions')->insert([
            'role_id' => 1,
            'menu_id' => $menuId,
            'can_view' => 1,
            'can_add' => 0,
            'can_edit' => 0,
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

    public function down(): void
    {
        $menuId = DB::table('menus')->where('slug', 'pages:administration-user-activity')->value('id');

        DB::table('role_menu_permissions')->where('menu_id', $menuId)->delete();
        DB::table('menus')->where('id', $menuId)->delete();
    }
};
