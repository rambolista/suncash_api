<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Reports > Money Transfer" joins `cashout_transactionsv3` to
 * `cashout_transaction_detailsv3` on `cashout_id` — which had NO index at
 * all, so MySQL drove the join with a full table scan of
 * `cashout_transaction_detailsv3` (confirmed via EXPLAIN: `type: ALL`,
 * `Using temporary; Using filesort`) before ever reaching the `status` /
 * `date_requested` filter on the other side. `status` (always filtered —
 * 0=pending or 1=completed) + `date_requested` (always the sort, and
 * usually a range filter too) had no usable index either.
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
        $this->addIndex('cashout_transaction_detailsv3', 'idxCashoutId', 'cashout_id');
        $this->addIndex('cashout_transactionsv3', 'idxStatusDateRequested', 'status, date_requested');
    }

    public function down(): void
    {
        $indexes = [
            'cashout_transaction_detailsv3' => 'idxCashoutId',
            'cashout_transactionsv3' => 'idxStatusDateRequested',
        ];

        foreach ($indexes as $table => $index) {
            if ($this->indexExists($table, $index)) {
                DB::connection('mysuncash')->statement("ALTER TABLE {$table} DROP INDEX {$index}");
            }
        }
    }
};
