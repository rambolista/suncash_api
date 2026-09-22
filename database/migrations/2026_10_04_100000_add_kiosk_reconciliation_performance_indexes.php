<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Kiosk > Reports > Cash Exposure" and "Reconciliation" both query
 * `kiosk_terminal_transactions` for manual credit/debit float adjustments,
 * and Reconciliation also queries `kiosk_meters_user` for REPLENISH/
 * PRINT_CLEAR meter-clear events — neither table had any index beyond its
 * own primary key. Two different real access patterns on
 * `kiosk_terminal_transactions`: Cash Exposure's batched "today's
 * recycled-to-kiosk total" (grouped across all terminals, no terminal_id
 * filter) and Reconciliation's per-terminal adjustment lookup (equality on
 * terminal_id + trans_type, then a date range) — indexed separately since
 * neither filter shape is a prefix of the other.
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
        $this->addIndex('kiosk_terminal_transactions', 'idxTransTypeDepositLocationCreateDate', 'trans_type, deposit_location, create_date');
        $this->addIndex('kiosk_terminal_transactions', 'idxTerminalIdTransTypeCreateDate', 'terminal_id, trans_type, create_date');
        $this->addIndex('kiosk_meters_user', 'idxTerminalIdTimestamp', 'terminal_id, timestamp');
    }

    public function down(): void
    {
        $indexes = [
            'kiosk_terminal_transactions' => ['idxTransTypeDepositLocationCreateDate', 'idxTerminalIdTransTypeCreateDate'],
            'kiosk_meters_user' => ['idxTerminalIdTimestamp'],
        ];

        foreach ($indexes as $table => $names) {
            foreach ($names as $index) {
                if ($this->indexExists($table, $index)) {
                    DB::connection('mysuncash')->statement("ALTER TABLE {$table} DROP INDEX {$index}");
                }
            }
        }
    }
};
