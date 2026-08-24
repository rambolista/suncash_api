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
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'first_name')) {
                $table->string('first_name')->nullable()->after('name');
            }

            if (! Schema::hasColumn('users', 'middle_name')) {
                $table->string('middle_name')->nullable()->after('first_name');
            }

            if (! Schema::hasColumn('users', 'last_name')) {
                $table->string('last_name')->nullable()->after('middle_name');
            }
        });

        DB::table('users')
            ->select(['id', 'name'])
            ->orderBy('id')
            ->chunkById(200, function ($users) {
                foreach ($users as $user) {
                    $parts = preg_split('/\s+/', trim((string) $user->name)) ?: [];
                    $first = $parts[0] ?? null;
                    $last = count($parts) > 1 ? $parts[count($parts) - 1] : null;
                    $middle = count($parts) > 2 ? implode(' ', array_slice($parts, 1, -1)) : null;

                    DB::table('users')
                        ->where('id', $user->id)
                        ->update([
                            'first_name' => $first ?: null,
                            'middle_name' => $middle ?: null,
                            'last_name' => $last ?: null,
                        ]);
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
                Schema::hasColumn('users', 'first_name') ? 'first_name' : null,
                Schema::hasColumn('users', 'middle_name') ? 'middle_name' : null,
                Schema::hasColumn('users', 'last_name') ? 'last_name' : null,
            ]);

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
