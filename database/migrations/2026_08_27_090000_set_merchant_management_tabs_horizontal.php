<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The vertical sidebar layout made per-tab descriptions unreadable at a
 * useful width. Switching to the horizontal scroll-tabs pattern used by
 * iBIMSKP's Reports page instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('menus')
            ->where('slug', 'pages:merchants-registration')
            ->update(['tab_layout' => 'horizontal']);
    }

    public function down(): void
    {
        DB::table('menus')
            ->where('slug', 'pages:merchants-registration')
            ->update(['tab_layout' => 'vertical']);
    }
};
