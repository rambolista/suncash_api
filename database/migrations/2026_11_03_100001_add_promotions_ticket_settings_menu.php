<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Adds "Promo Ticket Settings" (legacy `tools/ticket_promo_settings`) under "Promotions": view the list, add / copy, update and delete ticket promos. */
return new class extends Migration
{
    public function up(): void
    {
        $promotionsId = DB::table('menus')->where('slug', 'pages:promotions')->value('id');
        if (! $promotionsId) {
            return;
        }

        $now = now();

        $menuId = DB::table('menus')->insertGetId([
            'parent_id' => $promotionsId,
            'label' => 'Promo Ticket Settings',
            'slug' => 'pages:promotions-ticket-settings',
            'url' => '/promotions/ticket-settings',
            'icon' => 'ticket',
            'sort_order' => 4,
            'is_title' => 0,
            'is_active' => 1,
            'is_disabled' => 0,
            'is_special' => 0,
            'tab_layout' => 'horizontal',
            'supports_view' => 1,
            'supports_add' => 1,
            'supports_edit' => 1,
            'supports_delete' => 1,
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
            'role_id' => 1, 'menu_id' => $menuId,
            'can_view' => 1, 'can_add' => 1, 'can_edit' => 1, 'can_delete' => 1, 'can_approve' => 0,
            'can_execute' => 0, 'can_cancel' => 0, 'can_reverse' => 0, 'can_export' => 0, 'can_print' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $menuId = DB::table('menus')->where('slug', 'pages:promotions-ticket-settings')->value('id');
        if (! $menuId) {
            return;
        }

        DB::table('role_menu_permissions')->where('menu_id', $menuId)->delete();
        DB::table('menus')->where('id', $menuId)->delete();
    }
};
