<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds "Revenue Share Management" and "Bank Accounts" (legacy
 * `tools/revshare_management`, `tools/bank_accounts`) as the 7th and 8th
 * items under the existing "Tools" section, right after "SMS Responses".
 */
return new class extends Migration
{
    public function up(): void
    {
        $sectionId = DB::table('menus')->where('slug', 'pages:tools')->value('id');
        if (! $sectionId) {
            return;
        }

        $now = now();

        $children = [
            [
                'label' => 'Revenue Share Management', 'slug' => 'pages:tools-revshare-management', 'url' => '/tools/revshare-management',
                'icon' => 'chart-pie', 'sort_order' => 6,
                'supports_add' => 0, 'supports_edit' => 0, 'supports_delete' => 0,
            ],
            [
                'label' => 'Bank Accounts', 'slug' => 'pages:tools-bank-accounts', 'url' => '/tools/bank-accounts',
                'icon' => 'building-bank', 'sort_order' => 7,
                'supports_add' => 1, 'supports_edit' => 1, 'supports_delete' => 0,
            ],
        ];

        foreach ($children as $child) {
            $menuId = DB::table('menus')->insertGetId(array_merge($child, [
                'parent_id' => $sectionId,
                'is_title' => 0,
                'is_active' => 1,
                'is_disabled' => 0,
                'is_special' => 0,
                'tab_layout' => 'horizontal',
                'supports_view' => 1,
                'supports_approve' => 0,
                'supports_execute' => 0,
                'supports_cancel' => 0,
                'supports_reverse' => 0,
                'supports_export' => 0,
                'supports_print' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]));

            DB::table('role_menu_permissions')->insert([
                'role_id' => 1,
                'menu_id' => $menuId,
                'can_view' => 1,
                'can_add' => $child['supports_add'],
                'can_edit' => $child['supports_edit'],
                'can_delete' => $child['supports_delete'],
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
        $menuIds = DB::table('menus')->whereIn('slug', ['pages:tools-revshare-management', 'pages:tools-bank-accounts'])->pluck('id');
        DB::table('role_menu_permissions')->whereIn('menu_id', $menuIds)->delete();
        DB::table('menus')->whereIn('id', $menuIds)->delete();
    }
};
