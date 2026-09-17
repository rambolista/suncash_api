<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Promotions > Grand Draw" menu item, positioned right after "Ticket
 * Reports" (bumping "Settings" 1→2 and "Sign Up Promotion" 2→3). Mirrors
 * 2026_08_27_140000_add_promotions_menu.php's permission pattern (direct
 * grant to role_id=1) rather than the Kiosk migrations' sibling-backfill
 * pattern, for consistency with the rest of this Promotions section.
 */
return new class extends Migration
{
    public function up(): void
    {
        $promotionsId = DB::table('menus')->where('slug', 'pages:promotions')->value('id');
        if (! $promotionsId) {
            return;
        }

        $now = now();

        DB::table('menus')->where('parent_id', $promotionsId)->where('sort_order', '>=', 1)->increment('sort_order');

        $menuId = DB::table('menus')->insertGetId([
            'label' => 'Grand Draw',
            'slug' => 'pages:promotions-grand-draw',
            'url' => '/promotions/grand-draw',
            'icon' => 'trophy',
            'parent_id' => $promotionsId,
            'sort_order' => 1,
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
            'supports_execute' => 1,
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
            'can_add' => 0,
            'can_edit' => 0,
            'can_delete' => 0,
            'can_approve' => 0,
            'can_execute' => 1,
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
        $promotionsId = DB::table('menus')->where('slug', 'pages:promotions')->value('id');
        $menuId = DB::table('menus')->where('slug', 'pages:promotions-grand-draw')->value('id');

        if ($menuId) {
            DB::table('role_menu_permissions')->where('menu_id', $menuId)->delete();
            DB::table('menus')->where('id', $menuId)->delete();
        }
        if ($promotionsId) {
            DB::table('menus')->where('parent_id', $promotionsId)->where('sort_order', '>', 1)->decrement('sort_order');
        }
    }
};
