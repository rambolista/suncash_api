<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Customers > Customer Logs" / "Customer Failed Linking Logs" query
 * `customer_login_logs` (20k+ rows, growing forever) with only a PRIMARY
 * KEY index — every load was a full table scan regardless of the date
 * range, made worse by wrapping the column in `DATE(timestamp)` in the
 * WHERE clause (see CustomerLoginLogService), which defeats any index on
 * `timestamp` even once one exists. Adds a composite index covering both
 * pages' exact filter shape (`status` + a `timestamp` range).
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
        if (! $this->indexExists('customer_login_logs', 'idxStatusTimestamp')) {
            DB::connection('mysuncash')->statement(
                'ALTER TABLE customer_login_logs ADD INDEX idxStatusTimestamp (status, timestamp)'
            );
        }
    }

    public function down(): void
    {
        if ($this->indexExists('customer_login_logs', 'idxStatusTimestamp')) {
            DB::connection('mysuncash')->statement(
                'ALTER TABLE customer_login_logs DROP INDEX idxStatusTimestamp'
            );
        }
    }
};
