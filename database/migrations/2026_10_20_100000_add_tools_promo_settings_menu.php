<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Tools > Summer Cool Down Reloaded Promo Settings" — legacy's
 * `tools/wu_promo_items_management`, whose sidebar label is computed from
 * SUNCASH_ACTIVE_PROMO ("summer_cool_down_reloaded_promo" -> "Summer Cool
 * Down Reloaded Promo Settings", see menu.php line 357 and
 * config('promotions.active_code')). Legacy placed this under its "Tools"
 * section (after "Promo Settings" in the sidebar).
 *
 * The underlying feature (Cash Promo + Physical Item settings) already
 * exists as the "Promotions > Settings" page at /promotions/settings
 * (2026_08_27_140000_add_promotions_menu.php) — this adds a second menu
 * entry under Tools pointing at the SAME page/route, matching legacy's
 * Tools placement without duplicating the page or its API.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $toolsId = DB::table('menus')->where('slug', 'pages:tools')->value('id');

        $menuId = DB::table('menus')->insertGetId([
            'label' => 'Summer Cool Down Reloaded Promo Settings',
            'slug' => 'pages:tools-promo-settings',
            'url' => '/promotions/settings',
            'icon' => 'gift',
            'sort_order' => 20,
            'parent_id' => $toolsId,
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
            'role_id' => 1,
            'menu_id' => $menuId,
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
        ]);
    }

    public function down(): void
    {
        $menuId = DB::table('menus')->where('slug', 'pages:tools-promo-settings')->value('id');
        DB::table('role_menu_permissions')->where('menu_id', $menuId)->delete();
        DB::table('menus')->where('id', $menuId)->delete();
    }
};
