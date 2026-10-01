<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Legacy's `promo_items.item_description` is `VARCHAR(22)` — far too small
 * for a real prize description (e.g. "PS5 Prize - Holiday Bundle" already
 * overflows it). MySQL silently truncates on INSERT/UPDATE in non-strict
 * mode rather than erroring, so the Physical Item Settings Add/Update forms
 * were losing data without any validation error surfacing. Widening is
 * additive/non-destructive — existing (already-truncated) values are
 * unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::connection('mysuncash')->hasColumn('promo_items', 'item_description')) {
            return;
        }

        DB::connection('mysuncash')->statement('ALTER TABLE `promo_items` MODIFY COLUMN `item_description` VARCHAR(255) NULL');
    }

    public function down(): void
    {
        if (! Schema::connection('mysuncash')->hasColumn('promo_items', 'item_description')) {
            return;
        }

        DB::connection('mysuncash')->statement('ALTER TABLE `promo_items` MODIFY COLUMN `item_description` VARCHAR(22) NULL');
    }
};
