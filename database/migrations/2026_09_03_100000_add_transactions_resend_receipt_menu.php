<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Legacy admin's "Transactions > Resend Transaction Receipt"
 * (`Tools::resend_receipt_management()`), second of the 3 items under the
 * "Transactions" section (Void Transaction ported first; "Resend Voucher"
 * is not ported).
 */
return new class extends Migration
{
    private const TRANSACTIONS_MENU_ID = 329;

    public function up(): void
    {
        $now = now();

        $menuId = DB::table('menus')->insertGetId([
            'parent_id' => self::TRANSACTIONS_MENU_ID,
            'label' => 'Resend Transaction Receipt',
            'slug' => 'pages:transactions-resend-receipt',
            'url' => '/transactions/resend-receipt',
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
            'supports_execute' => 1,
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
            'can_edit' => 0,
            'can_delete' => 0,
            'can_approve' => 0,
            'can_execute' => 1,
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
        $menuId = DB::table('menus')->where('slug', 'pages:transactions-resend-receipt')->value('id');

        DB::table('role_menu_permissions')->where('menu_id', $menuId)->delete();
        DB::table('menus')->where('id', $menuId)->delete();
    }
};
