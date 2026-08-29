<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Legacy admin's "Merchant Statement" (merchant_ledger/index) and "Business
 * Billpay" (administrator/business_billpay) — siblings of Merchant
 * Settlements under "Merchants". Business Billpay needs `can_approve` for
 * its process/reject actions; Merchant Statement needs `can_edit` for its
 * balance-adjustment action.
 */
return new class extends Migration
{
    private const MERCHANTS_MENU_ID = 295;

    public function up(): void
    {
        $now = now();

        $menus = [
            ['label' => 'Merchant Statement', 'slug' => 'pages:merchants-statement', 'url' => '/merchants/statement', 'icon' => 'file-invoice', 'sort_order' => 4, 'supports_approve' => 0],
            ['label' => 'Business Billpay', 'slug' => 'pages:merchants-business-billpay', 'url' => '/merchants/business-billpay', 'icon' => 'receipt', 'sort_order' => 5, 'supports_approve' => 1],
        ];

        foreach ($menus as $menu) {
            $supportsApprove = $menu['supports_approve'];
            unset($menu['supports_approve']);

            $menuId = DB::table('menus')->insertGetId($menu + [
                'parent_id' => self::MERCHANTS_MENU_ID,
                'is_title' => 0,
                'is_active' => 1,
                'is_disabled' => 0,
                'is_special' => 0,
                'tab_layout' => 'horizontal',
                'supports_view' => 1,
                'supports_add' => 0,
                'supports_edit' => 1,
                'supports_delete' => 0,
                'supports_approve' => $supportsApprove,
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
                'can_approve' => $supportsApprove,
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
        $menuIds = DB::table('menus')
            ->whereIn('slug', ['pages:merchants-business-billpay', 'pages:merchants-statement'])
            ->pluck('id');

        DB::table('role_menu_permissions')->whereIn('menu_id', $menuIds)->delete();
        DB::table('menus')->whereIn('id', $menuIds)->delete();
    }
};
