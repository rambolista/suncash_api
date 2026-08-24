<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menus', function (Blueprint $table) {
            $table->boolean('is_disabled')->default(false)->after('is_active');
            $table->boolean('is_special')->default(false)->after('is_disabled');
        });

        DB::table('menus')->where('slug', 'disabled-menu')->update(['is_disabled' => true]);
        DB::table('menus')->where('slug', 'special-menu')->update(['is_special' => true]);
    }

    public function down(): void
    {
        Schema::table('menus', function (Blueprint $table) {
            $table->dropColumn(['is_disabled', 'is_special']);
        });
    }
};
