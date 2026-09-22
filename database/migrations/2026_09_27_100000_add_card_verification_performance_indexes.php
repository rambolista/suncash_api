<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Customers > Card Verification" lists `customer_creditcard` filtered by a
 * different combination of boolean flag columns per tab, then `ORDER BY
 * timestamp DESC`. Despite each flag having its own single-column index,
 * EXPLAIN showed every tab either index-merging several single-column
 * indexes or scanning the whole table, and always finishing with "Using
 * filesort" since nothing covers the sort:
 *
 * - pending:     index_merge(idxStatus, idxIsPending, idxIsVerified); filesort
 * - approved:    index_merge(idxStatus, idxIsVerified); filesort
 * - rejected:    ref(idxStatus) only, still filesort
 * - blacklisted: type ALL — no index touches `is_blacklisted` at all
 *
 * One composite index per tab's actual equality filters + `timestamp`
 * resolves each as a single ref lookup with no filesort. Same filters,
 * same query, just indexed.
 */
return new class extends Migration
{
    private function indexExists(string $table, string $indexName): bool
    {
        return DB::connection('mysuncash')->table('information_schema.statistics')
            ->where('table_schema', DB::connection('mysuncash')->getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $indexName)
            ->exists();
    }

    private function addIndex(string $table, string $indexName, string $columns): void
    {
        if (! $this->indexExists($table, $indexName)) {
            DB::connection('mysuncash')->statement("ALTER TABLE {$table} ADD INDEX {$indexName} ({$columns})");
        }
    }

    public function up(): void
    {
        $this->addIndex('customer_creditcard', 'idxCCPendingTimestamp', 'is_pending, is_verified, status, timestamp');
        $this->addIndex('customer_creditcard', 'idxCCApprovedTimestamp', 'status, is_verified, timestamp');
        $this->addIndex('customer_creditcard', 'idxCCRejectedTimestamp', 'is_rejected, status, timestamp');
        $this->addIndex('customer_creditcard', 'idxCCBlacklistedTimestamp', 'is_blacklisted, timestamp');
    }

    public function down(): void
    {
        $indexes = ['idxCCPendingTimestamp', 'idxCCApprovedTimestamp', 'idxCCRejectedTimestamp', 'idxCCBlacklistedTimestamp'];

        foreach ($indexes as $index) {
            if ($this->indexExists('customer_creditcard', $index)) {
                DB::connection('mysuncash')->statement("ALTER TABLE customer_creditcard DROP INDEX {$index}");
            }
        }
    }
};
