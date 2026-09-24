<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Tools > SMS Logs" queries `smsgateway_logs` (45k+ rows spanning
 * 2021-present, growing forever) by a `timestamp` range, ordered by
 * `timestamp DESC` — but the table has no index besides its PRIMARY KEY, so
 * every load was a full table scan plus a filesort for the ordering. A
 * single index on `timestamp` covers both the range filter and the sort.
 * Also indexes `smsgateway_err_codes.status_code`, the join key used to
 * resolve each row's human-readable status.
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
        if (! $this->indexExists('smsgateway_logs', 'idxTimestamp')) {
            DB::connection('mysuncash')->statement(
                'ALTER TABLE smsgateway_logs ADD INDEX idxTimestamp (timestamp)'
            );
        }

        if (! $this->indexExists('smsgateway_err_codes', 'idxStatusCode')) {
            DB::connection('mysuncash')->statement(
                'ALTER TABLE smsgateway_err_codes ADD INDEX idxStatusCode (status_code)'
            );
        }
    }

    public function down(): void
    {
        if ($this->indexExists('smsgateway_logs', 'idxTimestamp')) {
            DB::connection('mysuncash')->statement(
                'ALTER TABLE smsgateway_logs DROP INDEX idxTimestamp'
            );
        }

        if ($this->indexExists('smsgateway_err_codes', 'idxStatusCode')) {
            DB::connection('mysuncash')->statement(
                'ALTER TABLE smsgateway_err_codes DROP INDEX idxStatusCode'
            );
        }
    }
};
