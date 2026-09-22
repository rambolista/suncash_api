<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Kiosk > Reports > Voucher Access" (and any other reader of `web_logs`
 * by `log_type` + a date range) had no index to work with — confirmed via
 * EXPLAIN: full table scan + filesort. `web_logs` is a shared audit-log
 * table written by many features this session (Archive, KYC Upgrade, Card
 * Verification blacklist, etc.), so it only grows over time. A composite
 * index on the actual filter + sort columns covers this report's query
 * shape without a filesort.
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
        if (! $this->indexExists('web_logs', 'idxLogTypeUpdatedDate')) {
            DB::connection('mysuncash')->statement(
                'ALTER TABLE web_logs ADD INDEX idxLogTypeUpdatedDate (log_type, updated_date)'
            );
        }
    }

    public function down(): void
    {
        if ($this->indexExists('web_logs', 'idxLogTypeUpdatedDate')) {
            DB::connection('mysuncash')->statement(
                'ALTER TABLE web_logs DROP INDEX idxLogTypeUpdatedDate'
            );
        }
    }
};
