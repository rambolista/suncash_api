<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Tools > Sanddollar Activation" (legacy `tools/sanddollar_list` +
 * `tools/sanddollar_activation_form`) — pairs a merchant's Sand Dollar
 * mobile wallet device. Legacy's list has no edit/delete, only an "Add
 * Account" button, so only can_view/can_add are granted here.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $toolsId = DB::table('menus')->where('slug', 'pages:tools')->value('id');

        $menuId = DB::table('menus')->insertGetId([
            'label' => 'Sanddollar Activation',
            'slug' => 'pages:tools-sanddollar-activation',
            'url' => '/tools/sanddollar-activation',
            'icon' => 'currency-dollar',
            'sort_order' => 21,
            'parent_id' => $toolsId,
            'is_title' => 0,
            'is_active' => 1,
            'is_disabled' => 0,
            'is_special' => 0,
            'tab_layout' => 'horizontal',
            'supports_view' => 1,
            'supports_add' => 1,
            'supports_edit' => 0,
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

        DB::table('role_menu_permissions')->insert([
            'role_id' => 1,
            'menu_id' => $menuId,
            'can_view' => 1,
            'can_add' => 1,
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
        $menuId = DB::table('menus')->where('slug', 'pages:tools-sanddollar-activation')->value('id');
        DB::table('role_menu_permissions')->where('menu_id', $menuId)->delete();
        DB::table('menus')->where('id', $menuId)->delete();
    }
};
