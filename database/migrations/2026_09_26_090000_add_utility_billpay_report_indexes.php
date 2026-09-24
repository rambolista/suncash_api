<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Reports > Utility Billpay" unions `billpay_web_transactions` (always
 * filtered by `status = 0` alongside the date range) with
 * `billpay_transactions` (joined to `customers` via `customers.mobile`,
 * which had NO index at all — confirmed via EXPLAIN: a full scan of
 * `customers` inside a nested loop for every `billpay_transactions` row).
 * Neither table had an index usable for its date-range filter either.
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
        $this->addIndex('billpay_web_transactions', 'idxStatusTransactionDate', 'status, transaction_date');
        $this->addIndex('billpay_transactions', 'idxTransactionDate', 'transaction_date');
        $this->addIndex('customers', 'idxMobile', 'mobile');
    }

    public function down(): void
    {
        $indexes = [
            'billpay_web_transactions' => 'idxStatusTransactionDate',
            'billpay_transactions' => 'idxTransactionDate',
            'customers' => 'idxMobile',
        ];

        foreach ($indexes as $table => $index) {
            if ($this->indexExists($table, $index)) {
                DB::connection('mysuncash')->statement("ALTER TABLE {$table} DROP INDEX {$index}");
            }
        }
    }
};
