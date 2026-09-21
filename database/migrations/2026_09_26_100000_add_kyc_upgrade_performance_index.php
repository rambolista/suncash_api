<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Customers > KYC Upgrade" lists `customers WHERE customer_access = ?
 * ORDER BY create_on`. `customer_access` is already indexed (`ctxType`),
 * but nothing covers the sort, so MySQL filesorts every matching row
 * (confirmed via EXPLAIN: "Using filesort") — the same shape as the
 * Settlements/Bank Loads fixes. A composite index covering the filter and
 * the sort lets it resolve without a filesort, same query, same results.
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

    public function up(): void
    {
        if (! $this->indexExists('customers', 'idxCustomerAccessCreateOn')) {
            DB::connection('mysuncash')->statement(
                'ALTER TABLE customers ADD INDEX idxCustomerAccessCreateOn (customer_access, create_on)'
            );
        }
    }

    public function down(): void
    {
        if ($this->indexExists('customers', 'idxCustomerAccessCreateOn')) {
            DB::connection('mysuncash')->statement(
                'ALTER TABLE customers DROP INDEX idxCustomerAccessCreateOn'
            );
        }
    }
};
