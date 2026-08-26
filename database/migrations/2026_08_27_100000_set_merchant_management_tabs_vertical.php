<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reverted per user preference: with 11 tabs, vertical keeps every tab
 * visible at once instead of hiding some behind horizontal scroll.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('menus')
            ->where('slug', 'pages:merchants-registration')
            ->update(['tab_layout' => 'vertical']);
    }

    public function down(): void
    {
        DB::table('menus')
            ->where('slug', 'pages:merchants-registration')
            ->update(['tab_layout' => 'horizontal']);
    }
};
