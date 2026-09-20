<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Adds "SMS Responses" (legacy `tools/sms_responses`) as the 6th item under the existing "Tools" section, right after "Customer Management". */
return new class extends Migration
{
    public function up(): void
    {
        $sectionId = DB::table('menus')->where('slug', 'pages:tools')->value('id');
        if (! $sectionId) {
            return;
        }

        $now = now();

        $menuId = DB::table('menus')->insertGetId([
            'parent_id' => $sectionId,
            'label' => 'SMS Responses',
            'slug' => 'pages:tools-sms-responses',
            'url' => '/tools/sms-responses',
            'icon' => 'message-2-cog',
            'sort_order' => 5,
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
        $menuId = DB::table('menus')->where('slug', 'pages:tools-sms-responses')->value('id');
        if ($menuId) {
            DB::table('role_menu_permissions')->where('menu_id', $menuId)->delete();
            DB::table('menus')->where('id', $menuId)->delete();
        }
    }
};
