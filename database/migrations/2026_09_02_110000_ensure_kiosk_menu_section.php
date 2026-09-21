<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Creates the "Kiosk" top-level section ahead of its children (Kiosk
 * Management, Kiosk Statement, Kiosk Users, the various Kiosk Reports
 * migrations, ...) — a dozen of them, all resolving their parent via
 * `where('slug', 'pages:kiosk')`, which only works if this section already
 * exists at that point.
 *
 * It used to only get created by `add_kiosk_monitoring_dashboard_menu`
 * (dated 2026-09-04), which is AFTER every one of those children. Harmless
 * on this dev database, where migrations were run incrementally in the
 * order they were authored rather than strict filename order — but
 * `php artisan migrate` on a fresh install always runs strictly by
 * filename, so every one of those children ended up with no resolvable
 * parent (silently landing elsewhere in the sidebar, e.g. under
 * "Administration", instead of under "Kiosk").
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('menus')->where('slug', 'pages:kiosk')->exists()) {
            return;
        }

        $now = now();
        $mainMenuId = DB::table('menus')->where('slug', 'main')->whereNull('parent_id')->value('id');

        DB::table('menus')->where('parent_id', $mainMenuId)->where('sort_order', '>=', 5)->increment('sort_order');

        DB::table('menus')->insert([
            'parent_id' => $mainMenuId,
            'label' => 'Kiosk',
            'slug' => 'pages:kiosk',
            'url' => null,
            'icon' => 'device-desktop-analytics',
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
    }

    public function down(): void
    {
        $sectionId = DB::table('menus')->where('slug', 'pages:kiosk')->value('id');
        if (! $sectionId || DB::table('menus')->where('parent_id', $sectionId)->exists()) {
            // Still has children (or never existed) — nothing safe to remove here.
            return;
        }

        $mainMenuId = DB::table('menus')->where('slug', 'main')->whereNull('parent_id')->value('id');

        DB::table('menus')->where('id', $sectionId)->delete();
        DB::table('menus')->where('parent_id', $mainMenuId)->where('sort_order', '>', 5)->decrement('sort_order');
    }
};
