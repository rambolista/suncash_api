<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'mobile_number')) {
                $table->string('mobile_number', 50)->nullable()->after('email');
            }

            if (! Schema::hasColumn('users', 'address')) {
                $table->text('address')->nullable()->after('mobile_number');
            }

            if (! Schema::hasColumn('users', 'avatar_path')) {
                $table->string('avatar_path')->nullable()->after('address');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $columns = array_filter([
                Schema::hasColumn('users', 'mobile_number') ? 'mobile_number' : null,
                Schema::hasColumn('users', 'address') ? 'address' : null,
                Schema::hasColumn('users', 'avatar_path') ? 'avatar_path' : null,
            ]);

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
