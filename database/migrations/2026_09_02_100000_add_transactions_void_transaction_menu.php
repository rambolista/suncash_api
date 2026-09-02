<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Legacy admin's "Transactions" sidebar section (`#menu_void_trans`) has 3
 * items — Void Transaction, Resend Transaction Receipt, Resend Voucher — all
 * on the shared `tools.php` controller. Only "Void Transaction" is added
 * here; the other two are separate, unrequested features.
 *
 * Inserted right after "Terminals" (sort_order 3), so Float Management/
 * Giftcards/Promotions shift down by one.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $mainMenuId = DB::table('menus')->where('slug', 'main')->whereNull('parent_id')->value('id');

        DB::table('menus')->where('parent_id', $mainMenuId)->where('sort_order', '>=', 4)->increment('sort_order');

        $sectionId = DB::table('menus')->insertGetId([
            'parent_id' => $mainMenuId,
            'label' => 'Transactions',
            'slug' => 'pages:transactions',
            'url' => null,
            'icon' => 'transfer',
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
            'supports_approve' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $menuId = DB::table('menus')->insertGetId([
            'parent_id' => $sectionId,
            'label' => 'Void Transaction',
            'slug' => 'pages:transactions-void',
            'url' => '/transactions/void-transaction',
            'icon' => 'receipt-refund',
            'sort_order' => 0,
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
            'supports_reverse' => 1,
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
            'can_execute' => 0,
            'can_cancel' => 0,
            'can_reverse' => 1,
            'can_export' => 0,
            'can_print' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $mainMenuId = DB::table('menus')->where('slug', 'main')->whereNull('parent_id')->value('id');

        $menuIds = DB::table('menus')->whereIn('slug', ['pages:transactions', 'pages:transactions-void'])->pluck('id');
        DB::table('role_menu_permissions')->whereIn('menu_id', $menuIds)->delete();
        DB::table('menus')->whereIn('id', $menuIds)->delete();

        DB::table('menus')->where('parent_id', $mainMenuId)->where('sort_order', '>', 4)->decrement('sort_order');
    }
};
