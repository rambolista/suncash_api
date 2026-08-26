<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Legacy admin's "Settings" sidebar section (Change Password, Notifications,
 * SunCash Customer App, SunCash WU) — Change Password is skipped since the
 * new system already has that. Adds "Settings" as a dropdown parent under
 * "Administration" (same pattern as "Access Management"), with Notifications
 * / SunCash Customer App / SunCash WU as its children.
 */
return new class extends Migration
{
    private const ADMINISTRATION_MENU_ID = 285;

    public function up(): void
    {
        $now = now();

        $settingsId = DB::table('menus')->insertGetId([
            'label' => 'Settings',
            'slug' => 'pages:settings',
            'url' => null,
            'icon' => 'settings',
            'parent_id' => self::ADMINISTRATION_MENU_ID,
            'sort_order' => 1,
            'is_title' => 0,
            'is_active' => 1,
            'is_disabled' => 0,
            'is_special' => 0,
            'tab_layout' => 'horizontal',
            'supports_view' => 1,
            'supports_add' => 0,
            'supports_edit' => 1,
            'supports_delete' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $children = [
            ['label' => 'Notifications', 'slug' => 'pages:settings-notifications', 'url' => '/settings/notifications', 'icon' => 'bell', 'sort_order' => 0],
            ['label' => 'SunCash Customer App', 'slug' => 'pages:settings-customer-app', 'url' => '/settings/customer-app', 'icon' => 'device-mobile', 'sort_order' => 1],
            ['label' => 'SunCash WU', 'slug' => 'pages:settings-wu', 'url' => '/settings/wu', 'icon' => 'send', 'sort_order' => 2],
        ];

        $childIds = [$settingsId];

        foreach ($children as $child) {
            $childIds[] = DB::table('menus')->insertGetId($child + [
                'parent_id' => $settingsId,
                'is_title' => 0,
                'is_active' => 1,
                'is_disabled' => 0,
                'is_special' => 0,
                'tab_layout' => 'horizontal',
                'supports_view' => 1,
                'supports_add' => 0,
                'supports_edit' => 1,
                'supports_delete' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach ($childIds as $menuId) {
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
    }

    public function down(): void
    {
        $menuIds = DB::table('menus')
            ->where('slug', 'pages:settings')
            ->orWhereIn('slug', ['pages:settings-notifications', 'pages:settings-customer-app', 'pages:settings-wu'])
            ->pluck('id');

        DB::table('role_menu_permissions')->whereIn('menu_id', $menuIds)->delete();
        DB::table('menus')->whereIn('id', $menuIds)->delete();
    }
};
