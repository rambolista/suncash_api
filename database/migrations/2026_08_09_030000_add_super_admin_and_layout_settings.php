<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('super_admin')->default(false)->after('status');
        });

        Schema::create('layout_settings', function (Blueprint $table) {
            $table->id();
            $table->json('settings');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::table('layout_settings')->insert([
            'id' => 1,
            'settings' => json_encode([
                'skin' => 'default',
                'theme' => 'light',
                'orientation' => 'vertical',
                'sidenavSize' => 'default',
                'sidenavColor' => 'dark',
                'topbarColor' => 'light',
                'width' => 'fluid',
                'position' => 'fixed',
                'dir' => 'ltr',
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('layout_settings');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('super_admin');
        });
    }
};
