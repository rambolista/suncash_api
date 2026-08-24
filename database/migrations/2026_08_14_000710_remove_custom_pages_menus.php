<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $slugs = [
            'custom-pages',
            'pages:pages-empty',
            'authentication',
            'error-pages',
        ];

        DB::table('menus')->whereIn('slug', $slugs)->delete();
    }

    public function down(): void
    {
    }
};
