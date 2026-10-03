<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Reports > Users / Client Management" — filters that had nothing to use:
 *  - ezkard_transactions (Significant Deposits/Withdrawals): `trans_type_id`
 *    + a timestamp range, over the biggest table here. `timestamp` is a
 *    VARCHAR(750), so the index uses a 24-char prefix (a date-time is 19).
 *  - ezkard_pins (Change Pin History): joined on ezkard_id and range-filtered
 *    on date_modified — PK only. Composite (date_modified, ezkard_id) lets the
 *    range scan read both from the index.
 *  - customers.create_on (Users Profile): range + ORDER BY create_on DESC; the
 *    existing index leads with customer_access, which this never filters.
 */
return new class extends Migration
{
    private const INDEXES = [
        'ezkard_transactions' => ['idxTypeTimestamp' => 'trans_type_id, `timestamp`(24)'],
        'ezkard_pins' => ['idxDateModifiedEzkard' => 'DATE_MODIFIED, EZKARD_ID'],
        'customers' => ['idxCreateOn' => 'create_on'],
    ];

    private function indexExists(string $table, string $indexName): bool
    {
        return DB::connection('mysuncash')->table('information_schema.statistics')
            ->where('table_schema', DB::connection('mysuncash')->getDatabaseName())
            ->where('table_name', $table)->where('index_name', $indexName)->exists();
    }

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $name => $columns) {
                if (! $this->indexExists($table, $name)) {
                    DB::connection('mysuncash')->statement("ALTER TABLE {$table} ADD INDEX {$name} ({$columns})");
                }
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach (array_keys($indexes) as $name) {
                if ($this->indexExists($table, $name)) {
                    DB::connection('mysuncash')->statement("ALTER TABLE {$table} DROP INDEX {$name}");
                }
            }
        }
    }
};
