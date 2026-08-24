<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('menu_tabs')
            ->where('key', 'information')
            ->whereIn('menu_id', function ($query): void {
                $query->select('id')
                    ->from('menus')
                    ->where('slug', 'pages:apps-customers');
            })
            ->update([
                'label' => 'Personal/Business Info',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('menu_tabs')
            ->where('key', 'information')
            ->whereIn('menu_id', function ($query): void {
                $query->select('id')
                    ->from('menus')
                    ->where('slug', 'pages:apps-customers');
            })
            ->update([
                'label' => 'Personal / Business Information',
                'updated_at' => now(),
            ]);
    }
};
