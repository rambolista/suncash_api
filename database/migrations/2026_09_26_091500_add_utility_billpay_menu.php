<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Adds "Utility Billpay" (legacy `utility_billpay_report`, renamed from "Utility Billpay Report" since it lives inside "Reports") as the second child of the existing "Reports" menu. */
return new class extends Migration
{
    public function up(): void
    {
        $reportsId = DB::table('menus')->where('slug', 'pages:reports')->value('id');
        if (! $reportsId) {
            return;
        }

        $now = now();

        $utilityBillpayId = DB::table('menus')->insertGetId([
            'parent_id' => $reportsId,
            'label' => 'Utility Billpay',
            'slug' => 'pages:reports-utility-billpay',
            'url' => '/reports/utility-billpay',
            'icon' => 'receipt',
            'sort_order' => 1,
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
            'supports_export' => 1,
            'supports_print' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('role_menu_permissions')->insert([
            'role_id' => 1, 'menu_id' => $utilityBillpayId,
            'can_view' => 1, 'can_add' => 0, 'can_edit' => 0, 'can_delete' => 0, 'can_approve' => 0,
            'can_execute' => 0, 'can_cancel' => 0, 'can_reverse' => 0, 'can_export' => 1, 'can_print' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $utilityBillpayId = DB::table('menus')->where('slug', 'pages:reports-utility-billpay')->value('id');
        if (! $utilityBillpayId) {
            return;
        }

        DB::table('role_menu_permissions')->where('menu_id', $utilityBillpayId)->delete();
        DB::table('menus')->where('id', $utilityBillpayId)->delete();
    }
};
