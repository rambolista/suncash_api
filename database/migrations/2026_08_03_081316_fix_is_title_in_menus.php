<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix migration: the original seed migration wrote is_title = 0 for all rows
 * because MySQL's upsert silently ignored the boolean value in certain configs.
 * This migration corrects the known section-title slugs.
 */
return new class extends Migration
{
    private const TITLE_SLUGS = [
        'main',
        'apps',
        'custom-pages',
        'layouts',
        'components',
        'menu-items',
    ];

    public function up(): void
    {
        DB::table('menus')
            ->whereIn('slug', self::TITLE_SLUGS)
            ->update(['is_title' => 1]);
    }

    public function down(): void
    {
        DB::table('menus')
            ->whereIn('slug', self::TITLE_SLUGS)
            ->update(['is_title' => 0]);
    }
};
