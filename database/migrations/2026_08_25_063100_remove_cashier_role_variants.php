<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ROLE_NAMES = ['cashier', 'cashiers'];

    public function up(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        DB::table('roles')
            ->whereIn(DB::raw('LOWER(TRIM(name))'), self::ROLE_NAMES)
            ->delete();
    }

    public function down(): void
    {
        // This data removal is intentionally irreversible.
    }
};

