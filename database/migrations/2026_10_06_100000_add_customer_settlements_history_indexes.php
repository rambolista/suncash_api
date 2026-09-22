<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Customers > Settlements > View Settlements" ("View Details" ->
 * "History") and the detail page's average-weekly-withdrawal stats both go
 * through `CustomerSettlementService::averageScope()` — `WHERE customer_id
 * = ? (or customer_number = ?) ... ORDER BY created_date DESC`. The
 * existing `idxCustomerId`/`idxCustomerNumber` indexes (from the earlier
 * Settlements N+1 fix) cover the equality filter but not the sort —
 * confirmed via EXPLAIN: "Using filesort" on both branches. Composite
 * indexes covering both close that gap; the single-column ones are left in
 * place since other queries may still rely on them.
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
        $this->addIndex('customer_settlements', 'idxCustomerIdCreatedDate', 'customer_id, created_date');
        $this->addIndex('customer_settlements', 'idxCustomerNumberCreatedDate', 'customer_number, created_date');
    }

    public function down(): void
    {
        foreach (['idxCustomerIdCreatedDate', 'idxCustomerNumberCreatedDate'] as $index) {
            if ($this->indexExists('customer_settlements', $index)) {
                DB::connection('mysuncash')->statement("ALTER TABLE customer_settlements DROP INDEX {$index}");
            }
        }
    }
};
