<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Legacy admin's "Giftcards" section (a single-item collapsible menu →
 * "Products", `Administrator::giftcards_list()`) — the review queue for
 * merchant-submitted gift-card products.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $mainMenuId = DB::table('menus')->where('slug', 'main')->whereNull('parent_id')->value('id');

        $sectionId = DB::table('menus')->insertGetId([
            'parent_id' => $mainMenuId,
            'label' => 'Giftcards',
            'slug' => 'pages:giftcards',
            'url' => null,
            'icon' => 'gift-card',
            'sort_order' => 5,
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

        $menuId = DB::table('menus')->insertGetId([
            'parent_id' => $sectionId,
            'label' => 'Products',
            'slug' => 'pages:giftcards-products',
            'url' => '/giftcards/products',
            'icon' => 'gift-card',
            'sort_order' => 0,
            'is_title' => 0,
            'is_active' => 1,
            'is_disabled' => 0,
            'is_special' => 0,
            'tab_layout' => 'horizontal',
            'supports_view' => 1,
            'supports_add' => 0,
            'supports_edit' => 1,
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
            'can_edit' => 1,
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
        $menuIds = DB::table('menus')
            ->whereIn('slug', ['pages:giftcards', 'pages:giftcards-products'])
            ->pluck('id');

        DB::table('role_menu_permissions')->whereIn('menu_id', $menuIds)->delete();
        DB::table('menus')->whereIn('id', $menuIds)->delete();
    }
};
