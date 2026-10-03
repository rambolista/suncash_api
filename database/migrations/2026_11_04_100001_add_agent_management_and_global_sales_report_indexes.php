<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Reports > Agent Management" counts each client's terminals (`terminals` had only its primary key, so every search
 * scanned the table). "Reports > Global Sales" filtered by branch on `webpos_transaction.branch_id` (a VARCHAR; it has a
 * single-column index) and by date together: the composite serves a branch + date range in one range scan.
 */
return new class extends Migration
{
    private const INDEXES = [
        'terminals' => ['idxClientId' => 'client_id'],
        'webpos_transaction' => ['idxBranchDate' => 'branch_id, transaction_date'],
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
