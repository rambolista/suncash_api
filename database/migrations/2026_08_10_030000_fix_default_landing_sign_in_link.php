<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('landing_page_sections')
            ->join('landing_pages', 'landing_pages.id', '=', 'landing_page_sections.landing_page_id')
            ->where('landing_pages.slug', 'default')
            ->where('landing_page_sections.primary_link_url', '/login')
            ->update(['landing_page_sections.primary_link_url' => '/auth/sign-in']);
    }

    public function down(): void
    {
        DB::table('landing_page_sections')
            ->join('landing_pages', 'landing_pages.id', '=', 'landing_page_sections.landing_page_id')
            ->where('landing_pages.slug', 'default')
            ->where('landing_page_sections.primary_link_url', '/auth/sign-in')
            ->update(['landing_page_sections.primary_link_url' => '/login']);
    }
};
