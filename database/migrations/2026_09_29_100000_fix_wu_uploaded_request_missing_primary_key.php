<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Customers > Documents" — clicking "View" silently did nothing. Root
 * cause: on this environment, `wu_uploaded_request.id` is a plain nullable
 * `int` column with no PRIMARY KEY / AUTO_INCREMENT — every row's `id` is
 * NULL (confirmed live: 171 rows, 0 with a value). The frontend calls
 * `setSelectedId(row.id)` on click; since `row.id` is `null` and the state
 * was already `null`, React sees no change and never re-renders — no
 * navigation, no error, nothing visible.
 *
 * This only affects environments where the table was never given a real
 * primary key (this appears to be a legacy/shared table, not one this
 * app's migrations originally created) — guarded so it's a no-op wherever
 * `id` is already a proper PRIMARY KEY (e.g. a from-scratch dev database).
 *
 * Backfills unique sequential ids for the NULL rows (ordered by
 * created_at, ties broken arbitrarily — order doesn't matter, only
 * uniqueness does), then adds AUTO_INCREMENT PRIMARY KEY so future rows
 * get one automatically too. Not reversible: down() intentionally does
 * nothing rather than discard the newly-assigned ids.
 */
return new class extends Migration
{
    private function hasPrimaryKey(string $table): bool
    {
        return DB::connection('mysuncash')->table('information_schema.statistics')
            ->where('table_schema', DB::connection('mysuncash')->getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', 'PRIMARY')
            ->exists();
    }

    public function up(): void
    {
        if ($this->hasPrimaryKey('wu_uploaded_request')) {
            return;
        }

        DB::connection('mysuncash')->statement('SET @rownum = 0');
        DB::connection('mysuncash')->statement(
            'UPDATE wu_uploaded_request SET id = (@rownum := @rownum + 1) WHERE id IS NULL ORDER BY created_at'
        );
        DB::connection('mysuncash')->statement(
            'ALTER TABLE wu_uploaded_request MODIFY id INT(20) NOT NULL AUTO_INCREMENT, ADD PRIMARY KEY (id)'
        );
    }

    public function down(): void
    {
        // Not reversible without discarding the ids assigned above — intentional no-op.
    }
};
