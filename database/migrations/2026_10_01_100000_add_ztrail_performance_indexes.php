<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Kiosk > Reports > Zout" was slow to load. `KioskZoutReportService::list()`
 * joins `ztrail` to `ztrail_details` on `settlement_no` — and neither table
 * has ANY index besides its own primary key (confirmed via EXPLAIN: both
 * sides show `type: ALL`, a full table scan, joined via "Block Nested Loop").
 * That's an O(n*m) join with no index to short-circuit it; at production
 * scale (kiosk settlements accumulate indefinitely) this is the dominant
 * cost. Indexing the join column on both sides turns it into an indexed
 * lookup instead of a full cross-scan.
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
        $this->addIndex('ztrail', 'idxSettlementNo', 'settlement_no');
        $this->addIndex('ztrail', 'idxKioskBranchId', 'kiosk_branch_id');
        $this->addIndex('ztrail_details', 'idxSettlementNo', 'settlement_no');
        $this->addIndex('ztrail_details', 'idxKioskTerminalId', 'kiosk_terminal_id');
    }

    public function down(): void
    {
        $indexes = [
            'ztrail' => ['idxSettlementNo', 'idxKioskBranchId'],
            'ztrail_details' => ['idxSettlementNo', 'idxKioskTerminalId'],
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
