<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Customers" started as its own top-level section (sibling to "Main" /
 * "Administration") but only ever grew one child ("KYC Upgrade") — moves it
 * to be a collapsible item under "Main" instead, alongside Merchants/
 * Terminals/Giftcards, matching how those were structured.
 */
return new class extends Migration
{
    private const CUSTOMERS_MENU_ID = 293;

    private const MAIN_MENU_SORT_ORDER = 6;

    public function up(): void
    {
        $mainMenuId = DB::table('menus')->where('slug', 'main')->whereNull('parent_id')->value('id');

        DB::table('menus')->where('id', self::CUSTOMERS_MENU_ID)->update([
            'parent_id' => $mainMenuId,
            'is_title' => 0,
            'sort_order' => self::MAIN_MENU_SORT_ORDER,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('menus')->where('id', self::CUSTOMERS_MENU_ID)->update([
            'parent_id' => null,
            'is_title' => 1,
            'sort_order' => 3,
            'updated_at' => now(),
        ]);
    }
};
