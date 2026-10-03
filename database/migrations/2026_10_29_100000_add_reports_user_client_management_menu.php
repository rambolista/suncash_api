<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds "Users / Client Management" under Reports: legacy `user_reports/index/userclient`,
 * whose "List of Reports" dropdown becomes tabs (access is granted per tab, as on Kiosk > Reports).
 */
return new class extends Migration
{
    private const TABS = [
        ['key' => 'user_profile', 'label' => 'Users Profile', 'icon' => 'user-circle'],
        ['key' => 'user_balance', 'label' => 'User Balance', 'icon' => 'wallet'],
        ['key' => 'change_pin', 'label' => 'Change Pin History', 'icon' => 'key'],
        ['key' => 'deposits', 'label' => 'Significant Deposits', 'icon' => 'arrow-big-down-lines'],
        ['key' => 'withdrawals', 'label' => 'Significant Withdrawals', 'icon' => 'arrow-big-up-lines'],
        ['key' => 'money_transfer', 'label' => 'Money Transfer', 'icon' => 'transfer'],
        ['key' => 'expired_kyc', 'label' => 'Expired KYC', 'icon' => 'id-badge-2'],
    ];

    public function up(): void
    {
        $reportsId = DB::table('menus')->where('slug', 'pages:reports')->value('id');
        if (! $reportsId) {
            return;
        }

        $now = now();
        $flags = ['supports_add' => 0, 'supports_edit' => 0, 'supports_delete' => 0, 'supports_approve' => 0, 'supports_execute' => 0, 'supports_cancel' => 0, 'supports_reverse' => 0, 'supports_print' => 0];

        $menuId = DB::table('menus')->insertGetId([
            'parent_id' => $reportsId,
            'label' => 'Users / Client Management',
            'slug' => 'pages:reports-user-client-management',
            'url' => '/reports/user-client-management',
            'icon' => 'users-group',
            'sort_order' => 6,
            'is_title' => 0,
            'is_active' => 1,
            'is_disabled' => 0,
            'is_special' => 0,
            'tab_layout' => 'horizontal',
            'supports_view' => 1,
            'supports_export' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ] + $flags);

        DB::table('role_menu_permissions')->insert([
            'role_id' => 1, 'menu_id' => $menuId,
            'can_view' => 1, 'can_add' => 0, 'can_edit' => 0, 'can_delete' => 0, 'can_approve' => 0,
            'can_execute' => 0, 'can_cancel' => 0, 'can_reverse' => 0, 'can_export' => 1, 'can_print' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        foreach (self::TABS as $index => $tab) {
            $tabId = DB::table('menu_tabs')->insertGetId([
                'menu_id' => $menuId, 'key' => $tab['key'], 'label' => $tab['label'], 'icon' => $tab['icon'],
                'sort_order' => $index, 'is_active' => 1, 'supports_view' => 1, 'supports_export' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ] + $flags);

            DB::table('role_menu_tab_permissions')->insert([
                'role_id' => 1, 'menu_tab_id' => $tabId,
                'can_view' => 1, 'can_add' => 0, 'can_edit' => 0, 'can_delete' => 0, 'can_approve' => 0,
                'can_execute' => 0, 'can_cancel' => 0, 'can_reverse' => 0, 'can_export' => 1, 'can_print' => 0,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $menuId = DB::table('menus')->where('slug', 'pages:reports-user-client-management')->value('id');
        if (! $menuId) {
            return;
        }

        DB::table('role_menu_tab_permissions')->whereIn('menu_tab_id', DB::table('menu_tabs')->where('menu_id', $menuId)->pluck('id'))->delete();
        DB::table('menu_tabs')->where('menu_id', $menuId)->delete();
        DB::table('role_menu_permissions')->where('menu_id', $menuId)->delete();
        DB::table('menus')->where('id', $menuId)->delete();
    }
};
