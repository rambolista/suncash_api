<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('layout_settings', function (Blueprint $table): void {
            $table->string('scope', 20)->default('admin')->after('id');
            $table->unique('scope');
        });

        DB::table('layout_settings')->whereNull('scope')->update(['scope' => 'admin']);

        $customerExists = DB::table('layout_settings')->where('scope', 'customer')->exists();
        if (! $customerExists) {
            DB::table('layout_settings')->insert([
                'scope' => 'customer',
                'settings' => json_encode([
                    'theme' => 'light',
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('layout_settings')->where('scope', 'customer')->delete();

        Schema::table('layout_settings', function (Blueprint $table): void {
            $table->dropUnique(['scope']);
            $table->dropColumn('scope');
        });
    }
};
