<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Check Transaction" (`KioskConfirmCustomerServiceService::sessionLogs()`) looks up the
 * session's `loginKiosk` row via `WHERE method = 'loginKiosk' ... ORDER BY id DESC LIMIT 1`,
 * trying `api_calls`, then `api_calls_2`, then `api_calls_3` in turn until it finds a match —
 * so the whole lookup is only as fast as its slowest shard. The three tables' indexes turned
 * out to be wildly inconsistent: `api_calls` has a plain `method` index, `api_calls_2` has a
 * composite index in the wrong column order for this query (`timestamp, method` — `method`
 * isn't the leftmost column, so it can't be used for a method-only WHERE), and `api_calls_3`
 * has no secondary index at all (full table scan every time). Normalizes all three to a
 * composite `(method, id)` index, so the lookup is a direct index seek on every shard and
 * `ORDER BY id DESC LIMIT 1` needs no separate sort.
 */
return new class extends Migration
{
    private const TABLES = ['api_calls', 'api_calls_2', 'api_calls_3'];

    private function indexExists(string $table, string $indexName): bool
    {
        return DB::connection('mysuncash')->table('information_schema.statistics')
            ->where('table_schema', DB::connection('mysuncash')->getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $indexName)
            ->exists();
    }

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! $this->indexExists($table, 'idxMethodId')) {
                DB::connection('mysuncash')->statement(
                    "ALTER TABLE {$table} ADD INDEX idxMethodId (method, id)"
                );
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if ($this->indexExists($table, 'idxMethodId')) {
                DB::connection('mysuncash')->statement(
                    "ALTER TABLE {$table} DROP INDEX idxMethodId"
                );
            }
        }
    }
};
