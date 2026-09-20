<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds "Forex Rate" and "Send SMS" (legacy `tools/forex`,
 * `tools/sms_to_cardholders`) as the 3rd and 4th items under the existing
 * "Tools" section, right after "Transaction Limits" — no sibling shift
 * needed, they just append.
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
                'label' => 'Forex Rate', 'slug' => 'pages:tools-forex-rate', 'url' => '/tools/forex-rate',
                'icon' => 'currency-dollar', 'sort_order' => 2,
                'supports_view' => 1, 'supports_add' => 1, 'supports_edit' => 0, 'supports_delete' => 0,
            ],
            [
                'label' => 'Send SMS', 'slug' => 'pages:tools-send-sms', 'url' => '/tools/send-sms',
                'icon' => 'message-2', 'sort_order' => 3,
                'supports_view' => 1, 'supports_add' => 0, 'supports_edit' => 0, 'supports_delete' => 0,
                'supports_execute' => 1,
            ],
        ];

        foreach ($children as $child) {
            $menuId = DB::table('menus')->insertGetId(array_merge([
                'parent_id' => $sectionId,
                'is_title' => 0,
                'is_active' => 1,
                'is_disabled' => 0,
                'is_special' => 0,
                'tab_layout' => 'horizontal',
                'supports_approve' => 0,
                'supports_execute' => 0,
                'supports_cancel' => 0,
                'supports_reverse' => 0,
                'supports_export' => 0,
                'supports_print' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ], $child));

            DB::table('role_menu_permissions')->insert([
                'role_id' => 1,
                'menu_id' => $menuId,
                'can_view' => 1,
                'can_add' => $child['supports_add'] ?? 0,
                'can_edit' => $child['supports_edit'] ?? 0,
                'can_delete' => 0,
                'can_approve' => 0,
                'can_execute' => $child['supports_execute'] ?? 0,
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
        $menuIds = DB::table('menus')->whereIn('slug', ['pages:tools-forex-rate', 'pages:tools-send-sms'])->pluck('id');
        DB::table('role_menu_permissions')->whereIn('menu_id', $menuIds)->delete();
        DB::table('menus')->whereIn('id', $menuIds)->delete();
    }
};
