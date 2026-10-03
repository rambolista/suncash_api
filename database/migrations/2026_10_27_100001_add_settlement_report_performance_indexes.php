<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Reports > Settlement" joins client_transactions -> client_transaction_details
 * (on client_transaction_id) -> rev_share_transaction_details (on
 * revshare_log_id). Both join columns had no index (primary keys only), so the
 * planner had to drive from rev_share_transaction_details with a full scan, or
 * rescan the child table for every parent row. With them indexed it can start
 * from the day's client_transactions (idxTimestamp) and walk down by key.
 */
return new class extends Migration
{
    private const INDEXES = [
        'client_transaction_details' => ['idxClientTransactionAccount' => 'client_transaction_id, client_account_type'],
        'rev_share_transaction_details' => ['idxRevshareLogId' => 'revshare_log_id'],
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
