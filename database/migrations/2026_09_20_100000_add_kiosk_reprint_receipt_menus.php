<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Kiosk > Reprint Receipt" and "Kiosk > Reprint Replenishment Receipt"
 * menu items, positioned right before "Reports" (bumping it from
 * sort_order 11 to 13). Both are read-only/print-only features — no
 * legacy send/SMS capability exists for either — so only `can_view` and
 * `can_print` are supported/granted. Permission backfill mirrors
 * 2026_09_18_100000_add_kiosk_voucher_pin_tool_menu.php: granted to every
 * role that already has can_view on a sibling Kiosk menu.
 */
return new class extends Migration
{
    private const MENUS = [
        ['label' => 'Reprint Receipt', 'slug' => 'pages:kiosk-reprint-receipt', 'url' => '/kiosk/reprint-receipt', 'icon' => 'receipt', 'sort_order' => 11],
        ['label' => 'Reprint Replenishment Receipt', 'slug' => 'pages:kiosk-reprint-replenishment-receipt', 'url' => '/kiosk/reprint-replenishment-receipt', 'icon' => 'receipt-2', 'sort_order' => 12],
    ];

    public function up(): void
    {
        $kioskMenuId = DB::table('menus')->where('slug', 'pages:kiosk')->value('id');
        if (! $kioskMenuId) {
            return;
        }

        $now = now();

        DB::table('menus')->where('parent_id', $kioskMenuId)->where('sort_order', '>=', 11)->increment('sort_order', count(self::MENUS));

        $newMenuIds = [];
        foreach (self::MENUS as $menu) {
            $newMenuIds[] = DB::table('menus')->insertGetId([
                'label' => $menu['label'],
                'slug' => $menu['slug'],
                'url' => $menu['url'],
                'icon' => $menu['icon'],
                'parent_id' => $kioskMenuId,
                'sort_order' => $menu['sort_order'],
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
                'supports_execute' => 0,
                'supports_cancel' => 0,
                'supports_reverse' => 0,
                'supports_export' => 0,
                'supports_print' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $siblingIds = DB::table('menus')->where('parent_id', $kioskMenuId)->whereNotIn('id', $newMenuIds)->pluck('id');

        $roleIds = DB::table('role_menu_permissions')
            ->whereIn('menu_id', $siblingIds)
            ->where('can_view', 1)
            ->distinct()
            ->pluck('role_id');

        foreach ($newMenuIds as $menuId) {
            $roleIds->each(function ($roleId) use ($menuId, $now) {
                DB::table('role_menu_permissions')->updateOrInsert(
                    ['role_id' => $roleId, 'menu_id' => $menuId],
                    [
                        'can_view' => 1,
                        'can_add' => 0,
                        'can_edit' => 0,
                        'can_delete' => 0,
                        'can_approve' => 0,
                        'can_execute' => 0,
                        'can_cancel' => 0,
                        'can_reverse' => 0,
                        'can_export' => 0,
                        'can_print' => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );
            });
        }
    }

    public function down(): void
    {
        $kioskMenuId = DB::table('menus')->where('slug', 'pages:kiosk')->value('id');
        $slugs = array_column(self::MENUS, 'slug');
        $menuIds = DB::table('menus')->whereIn('slug', $slugs)->pluck('id');

        if ($menuIds->isNotEmpty()) {
            DB::table('role_menu_permissions')->whereIn('menu_id', $menuIds)->delete();
            DB::table('menus')->whereIn('id', $menuIds)->delete();
        }
        if ($kioskMenuId) {
            DB::table('menus')->where('parent_id', $kioskMenuId)->where('sort_order', '>', 12)->decrement('sort_order', count(self::MENUS));
        }
    }
};
