<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Adds "Payment Management" (legacy `tools/payment_management`) under the existing "Tools" section. */
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
            'label' => 'Payment Management',
            'slug' => 'pages:tools-payment-management',
            'url' => '/tools/payment-management',
            'icon' => 'cash-banknote',
            'sort_order' => 25,
            'is_title' => 0,
            'is_active' => 1,
            'is_disabled' => 0,
            'is_special' => 0,
            'tab_layout' => 'horizontal',
            'supports_view' => 1,
            'supports_add' => 1,
            'supports_edit' => 1,
            'supports_delete' => 0,
            'supports_approve' => 1,
            'supports_execute' => 1,
            'supports_cancel' => 1,
            'supports_reverse' => 0,
            'supports_export' => 1,
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
            'can_delete' => 0,
            'can_approve' => 1,
            'can_execute' => 1,
            'can_cancel' => 1,
            'can_reverse' => 0,
            'can_export' => 1,
            'can_print' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $menuId = DB::table('menus')->where('slug', 'pages:tools-payment-management')->value('id');
        if ($menuId) {
            DB::table('role_menu_permissions')->where('menu_id', $menuId)->delete();
            DB::table('menus')->where('id', $menuId)->delete();
        }
    }
};
