<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Customers > Bank Loads" is slow for the same reason Settlements was
 * (see 2026_09_23_110000): `CustomerBankLoadService::baseQuery()` filters
 * `transaction_type = 'LOAD' AND withdrawal_type = ''`, then `status`, then
 * `ORDER BY created_date DESC`. `transaction_type` has no index at all, and
 * none of the existing single-column indexes cover the sort, so MySQL
 * filesorts every matching row (confirmed via EXPLAIN: "Using filesort").
 * A single composite index covering all three filter columns plus the sort
 * lets this query resolve entirely from the index, no filesort.
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
        if (! $this->indexExists('customer_settlements', 'idxTypeWithdrawalStatusCreatedDate')) {
            DB::connection('mysuncash')->statement(
                'ALTER TABLE customer_settlements ADD INDEX idxTypeWithdrawalStatusCreatedDate (transaction_type, withdrawal_type, status, created_date)'
            );
        }
    }

    public function down(): void
    {
        if ($this->indexExists('customer_settlements', 'idxTypeWithdrawalStatusCreatedDate')) {
            DB::connection('mysuncash')->statement(
                'ALTER TABLE customer_settlements DROP INDEX idxTypeWithdrawalStatusCreatedDate'
            );
        }
    }
};
