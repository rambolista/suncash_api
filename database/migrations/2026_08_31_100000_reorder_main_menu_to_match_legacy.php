<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reorders "Main"'s children to match legacy admin's real top-level menu
 * order (`administrator/menu.php`'s default/full-admin branch, i.e. the
 * `else` branch — not the UserRole==1 or UserRole==3 restricted branches),
 * skipping "Administrator" itself (that maps to this app's separate
 * "Administration" section) and any legacy sections not yet ported
 * (Transactions, Kiosk, Locator, Reports, Tools, Settings):
 *
 * Dashboard (kept first, per explicit request) -> Merchants -> Customers ->
 * Terminals -> Float Management -> Giftcards -> Promotions.
 */
return new class extends Migration
{
    private const ORDER = [
        297 => 0, // Dashboard
        295 => 1, // Merchants
        293 => 2, // Customers
        316 => 3, // Terminals
        306 => 4, // Float Management
        318 => 5, // Giftcards
        302 => 6, // Promotions
    ];

    private const PREVIOUS_ORDER = [
        297 => 0, // Dashboard
        295 => 1, // Merchants
        302 => 2, // Promotions
        306 => 3, // Float Management
        316 => 4, // Terminals
        318 => 5, // Giftcards
        293 => 6, // Customers
    ];

    public function up(): void
    {
        $now = now();
        foreach (self::ORDER as $menuId => $sortOrder) {
            DB::table('menus')->where('id', $menuId)->update(['sort_order' => $sortOrder, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        $now = now();
        foreach (self::PREVIOUS_ORDER as $menuId => $sortOrder) {
            DB::table('menus')->where('id', $menuId)->update(['sort_order' => $sortOrder, 'updated_at' => $now]);
        }
    }
};
