<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('menus')) {
            DB::table('menus')
                ->where('slug', 'pages:landing')
                ->orWhere('url', '/landing')
                ->orWhereRaw('LOWER(TRIM(label)) = ?', ['landing'])
                ->delete();
        }

        if (Schema::hasTable('landing_pages')) {
            DB::table('landing_pages')
                ->whereIn('header_sign_up_url', ['/customer/login', '/customer/sign-up', '/customer/register'])
                ->update(['header_sign_up_url' => '/auth/sign-in']);
        }
    }

    public function down(): void
    {
        // This data cleanup is intentionally irreversible.
    }
};

