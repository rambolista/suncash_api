<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('menus')
            ->where('slug', 'pages:merchants-registration')
            ->update([
                'label' => 'Merchant Management',
                'icon' => 'building-store',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('menus')
            ->where('slug', 'pages:merchants-registration')
            ->update([
                'label' => 'Registration',
                'icon' => 'file-plus',
                'updated_at' => now(),
            ]);
    }
};
