<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            if (! Schema::hasColumn('roles', 'icon')) {
                $table->string('icon', 120)->nullable()->after('key_responsibilities');
            }
        });

        DB::table('roles')
            ->whereNull('icon')
            ->update(['icon' => 'shield']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            if (Schema::hasColumn('roles', 'icon')) {
                $table->dropColumn('icon');
            }
        });
    }
};
