<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Customers > Settlements" was timing out in production (Cloudflare 504).
 * `CustomerSettlementService::list()` queries `WHERE status = ? ORDER BY
 * created_date DESC` — `status` alone is indexed, but with no index
 * covering the sort, MySQL filesorts every matching row before it can even
 * apply the (also unindexed) `withdrawal_type != ''` filter. `customer_id`/
 * `customer_number` (used by history()/averageScope()) had no index either.
 *
 * Guarded via information_schema (not just `hasIndex`, which doesn't exist
 * on the query builder) since this shared `mysuncash` database may already
 * have some of this from elsewhere.
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
        if (! $this->indexExists('customer_settlements', 'idxStatusCreatedDate')) {
            DB::connection('mysuncash')->statement(
                'ALTER TABLE customer_settlements ADD INDEX idxStatusCreatedDate (status, created_date)'
            );
        }

        if (! $this->indexExists('customer_settlements', 'idxCustomerId')) {
            DB::connection('mysuncash')->statement(
                'ALTER TABLE customer_settlements ADD INDEX idxCustomerId (customer_id)'
            );
        }

        if (! $this->indexExists('customer_settlements', 'idxCustomerNumber')) {
            DB::connection('mysuncash')->statement(
                'ALTER TABLE customer_settlements ADD INDEX idxCustomerNumber (customer_number)'
            );
        }
    }

    public function down(): void
    {
        foreach (['idxStatusCreatedDate', 'idxCustomerId', 'idxCustomerNumber'] as $index) {
            if ($this->indexExists('customer_settlements', $index)) {
                DB::connection('mysuncash')->statement("ALTER TABLE customer_settlements DROP INDEX {$index}");
            }
        }
    }
};
