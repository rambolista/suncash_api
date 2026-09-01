<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Legacy admin's "Customers > Bank Loads" (`Administrator::customer_bankpayments()`)
 * — the review queue for customer wallet top-ups via manual bank deposit.
 * Filed under the existing "Customers" section (menu id 293), right after
 * "Settlements" to match legacy's own menu order (KYC Upgrade, Documents,
 * Card Verification, Settlements, Bank Loads, ...). "Archive" — the item
 * after Bank Loads in legacy — is not ported.
 */
return new class extends Migration
{
    private const CUSTOMERS_MENU_ID = 293;

    public function up(): void
    {
        $now = now();

        $menuId = DB::table('menus')->insertGetId([
            'parent_id' => self::CUSTOMERS_MENU_ID,
            'label' => 'Bank Loads',
            'slug' => 'pages:customers-bank-loads',
            'url' => '/customers/bank-loads',
            'icon' => 'building-bank',
            'sort_order' => 4,
            'is_title' => 0,
            'is_active' => 1,
            'is_disabled' => 0,
            'is_special' => 0,
            'tab_layout' => 'horizontal',
            'supports_view' => 1,
            'supports_add' => 0,
            'supports_edit' => 0,
            'supports_delete' => 0,
            'supports_approve' => 1,
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
            'can_approve' => 1,
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
        $menuId = DB::table('menus')->where('slug', 'pages:customers-bank-loads')->value('id');

        DB::table('role_menu_permissions')->where('menu_id', $menuId)->delete();
        DB::table('menus')->where('id', $menuId)->delete();
    }
};
