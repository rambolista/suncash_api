<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Kiosk > Reports > Transaction / Commission / Agent Commission" — all
 * three tabs share `KioskTransactionReportService::list()`, a 4-way
 * `UNION ALL` over `webpos_transaction_kiosk` (each branch filtered by
 * `status = 0 AND transaction_date BETWEEN ? AND ?`, with 5-8 LEFT/INNER
 * JOINs per branch) with no LIMIT and no pagination.
 *
 * `webpos_transaction_kiosk` had no index beyond its own primary key —
 * confirmed via EXPLAIN (`type: ALL`, full scan) and measured directly: a
 * one-year date range took 449ms for the SQL alone on just 4559 rows.
 * Three of the LEFT JOIN targets were also missing an index on their own
 * join column (`billpay_transactions.settlement_transaction_id`,
 * `sanddollar_trail.transaction_id`, `mgo_giftcard_transactions.transaction_id`),
 * compounding the cost per surviving row once the main table is filtered.
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
        $this->addIndex('webpos_transaction_kiosk', 'idxStatusTransactionDate', 'status, transaction_date');
        $this->addIndex('billpay_transactions', 'idxSettlementTransactionId', 'settlement_transaction_id');
        $this->addIndex('sanddollar_trail', 'idxTransactionId', 'transaction_id');
        $this->addIndex('mgo_giftcard_transactions', 'idxTransactionId', 'transaction_id');
    }

    public function down(): void
    {
        $indexes = [
            'webpos_transaction_kiosk' => 'idxStatusTransactionDate',
            'billpay_transactions' => 'idxSettlementTransactionId',
            'sanddollar_trail' => 'idxTransactionId',
            'mgo_giftcard_transactions' => 'idxTransactionId',
        ];

        foreach ($indexes as $table => $index) {
            if ($this->indexExists($table, $index)) {
                DB::connection('mysuncash')->statement("ALTER TABLE {$table} DROP INDEX {$index}");
            }
        }
    }
};
