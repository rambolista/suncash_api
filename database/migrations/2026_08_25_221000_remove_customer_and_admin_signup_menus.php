<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MENU_SLUGS_TO_REMOVE = [
        'pages:apps-customers',
        'pages:apps-access-management-customer-menus',
        'pages:auth-sign-up',
        'pages:auth-card-sign-up',
        'pages:auth-split-sign-up',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('menus')) {
            return;
        }

        DB::table('menus')
            ->whereIn('slug', self::MENU_SLUGS_TO_REMOVE)
            ->orWhereIn(DB::raw('LOWER(TRIM(label))'), ['customer list', 'customer menus'])
            ->delete();

        if (Schema::hasTable('landing_pages')) {
            DB::table('landing_pages')
                ->where('header_sign_up_url', '/auth/sign-up')
                ->update(['header_sign_up_url' => '/customer/register']);
        }
    }

    public function down(): void
    {
        // This data cleanup is intentionally irreversible.
    }
};

