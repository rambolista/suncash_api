<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `auditable_id` was `unsignedBigInteger`, which truncates (and on strict
 * MySQL, throws) when logging a model with a non-numeric key — e.g.
 * Illuminate\Notifications\DatabaseNotification, whose id is a UUID.
 * No doctrine/dbal in this project, so ->change() isn't available; raw
 * MODIFY does the same thing without adding a dependency for one column.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE activity_logs MODIFY auditable_id VARCHAR(191) NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE activity_logs MODIFY auditable_id BIGINT UNSIGNED NULL');
    }
};
