<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('menu_icons')) {
            Schema::create('menu_icons', function (Blueprint $table) {
                $table->id();
                $table->string('name', 120)->unique();
                $table->timestamps();
            });
        }

        $icons = require config_path('menu_icons.php');
        $timestamp = now();

        foreach (array_chunk($icons, 500) as $chunk) {
            DB::table('menu_icons')->insertOrIgnore(
                array_map(
                    static fn (string $name): array => [
                        'name' => $name,
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ],
                    $chunk
                )
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_icons');
    }
};
