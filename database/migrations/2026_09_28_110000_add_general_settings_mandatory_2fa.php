<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds "Access Management > General Settings" with a single toggle: whether
 * every admin must set up 2FA immediately after login before using the app.
 * Off by default so this doesn't suddenly lock out everyone who hasn't
 * configured 2FA yet. Enforced by the `require.2fa.setup` middleware.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::connection('mysuncash')->table('system_settings')->updateOrInsert(
            ['setting_type' => 'security_setting', 'set_code' => 'mandatory_2fa'],
            [
                'name' => 'Mandatory Two-Factor Authentication',
                'description' => 'When on, every admin must set up two-factor authentication immediately after login before they can use the app.',
                'set_value' => 'OFF',
                'is_enable' => 'true',
                'is_active' => 1,
            ]
        );

        $sectionId = DB::table('menus')->where('slug', 'pages:apps-access-management')->value('id');
        if (! $sectionId) {
            return;
        }

        $now = now();
        $menuId = DB::table('menus')->insertGetId([
            'parent_id' => $sectionId,
            'label' => 'General Settings',
            'slug' => 'pages:apps-access-management-general-settings',
            'url' => '/apps/access-management/general-settings',
            'icon' => 'settings',
            'sort_order' => 3,
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
        $menuId = DB::table('menus')->where('slug', 'pages:apps-access-management-general-settings')->value('id');
        if ($menuId) {
            DB::table('role_menu_permissions')->where('menu_id', $menuId)->delete();
            DB::table('menus')->where('id', $menuId)->delete();
        }

        DB::connection('mysuncash')->table('system_settings')
            ->where('setting_type', 'security_setting')
            ->where('set_code', 'mandatory_2fa')
            ->delete();
    }
};
