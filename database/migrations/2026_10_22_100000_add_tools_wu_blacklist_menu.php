<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Tools > WU Blacklist" (legacy `tools/wu_blacklist`) — a flat list of
 * names blocked from Western Union transactions, with Add + an
 * Active/Inactive toggle. No edit of the name itself and no delete.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $toolsId = DB::table('menus')->where('slug', 'pages:tools')->value('id');

        $menuId = DB::table('menus')->insertGetId([
            'label' => 'WU Blacklist',
            'slug' => 'pages:tools-wu-blacklist',
            'url' => '/tools/wu-blacklist',
            'icon' => 'ban',
            'sort_order' => 23,
            'parent_id' => $toolsId,
            'is_title' => 0,
            'is_active' => 1,
            'is_disabled' => 0,
            'is_special' => 0,
            'tab_layout' => 'horizontal',
            'supports_view' => 1,
            'supports_add' => 1,
            'supports_edit' => 1,
            'supports_delete' => 0,
            'supports_approve' => 0,
            'supports_execute' => 0,
            'supports_cancel' => 0,
            'supports_reverse' => 0,
            'supports_export' => 1,
            'supports_print' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('role_menu_permissions')->insert([
            'role_id' => 1,
            'menu_id' => $menuId,
            'can_view' => 1,
            'can_add' => 1,
            'can_edit' => 1,
            'can_delete' => 0,
            'can_approve' => 0,
            'can_execute' => 0,
            'can_cancel' => 0,
            'can_reverse' => 0,
            'can_export' => 1,
            'can_print' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $menuId = DB::table('menus')->where('slug', 'pages:tools-wu-blacklist')->value('id');
        DB::table('role_menu_permissions')->where('menu_id', $menuId)->delete();
        DB::table('menus')->where('id', $menuId)->delete();
    }
};
