<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Tools > Card Logs" queries `suncashme_checkout_logs` (29k+ rows spanning
 * 2018-present, growing forever) by `type IN (...)` + a `date_created`
 * range, but the table has no index besides its PRIMARY KEY — every load
 * is a full table scan regardless of the date range picked. `type` alone
 * isn't a great index lead column (the 3 types this report cares about are
 * ~62% of all rows), so `date_created` goes first: a day's worth of rows
 * out of years of history is far more selective, and `type` narrows the
 * rest within that range.
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
        if (! $this->indexExists('suncashme_checkout_logs', 'idxDateCreatedType')) {
            DB::connection('mysuncash')->statement(
                'ALTER TABLE suncashme_checkout_logs ADD INDEX idxDateCreatedType (date_created, type)'
            );
        }
    }

    public function down(): void
    {
        if ($this->indexExists('suncashme_checkout_logs', 'idxDateCreatedType')) {
            DB::connection('mysuncash')->statement(
                'ALTER TABLE suncashme_checkout_logs DROP INDEX idxDateCreatedType'
            );
        }
    }
};
