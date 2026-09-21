<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Legacy admin's "Customers > Settlements" (`Administrator::customer_settlement()`)
 * — the review queue for customer bank-withdrawal requests. Filed under the
 * existing "Customers" section (menu id 293), right after "Card Verification"
 * to match legacy's own menu order (KYC Upgrade, Customer Documents, Card
 * Verification, Settlements, ...). "Bank Loads" and "Archive" — the two
 * menu items after Settlements in legacy — are not ported.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // Resolved by slug, not a hardcoded id — see the note in
        // 2026_08_27_110000_nest_merchants_menu_under_main.php.
        $customersMenuId = DB::table('menus')->where('slug', 'customers')->value('id');

        $menuId = DB::table('menus')->insertGetId([
            'parent_id' => $customersMenuId,
            'label' => 'Settlements',
            'slug' => 'pages:customers-settlements',
            'url' => '/customers/settlements',
            'icon' => 'building-bank',
            'sort_order' => 3,
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
        $menuId = DB::table('menus')->where('slug', 'pages:customers-settlements')->value('id');

        DB::table('role_menu_permissions')->where('menu_id', $menuId)->delete();
        DB::table('menus')->where('id', $menuId)->delete();
    }
};
