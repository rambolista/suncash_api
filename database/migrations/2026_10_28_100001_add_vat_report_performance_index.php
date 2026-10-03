<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Reports > VAT" lists `webpos_transaction` rows where transaction_type =
 * 'MONEY_TRANSFER' AND status = 0 AND transaction_date in the window. Only
 * single-column indexes existed (type alone matches thousands of rows,
 * status/date were filtered row by row); this composite answers all three
 * conditions with one equality-then-range lookup.
 */
return new class extends Migration
{
    private function exists(): bool
    {
        return DB::connection('mysuncash')->table('information_schema.statistics')
            ->where('table_schema', DB::connection('mysuncash')->getDatabaseName())
            ->where('table_name', 'webpos_transaction')->where('index_name', 'idxTypeStatusDate')->exists();
    }

    public function up(): void
    {
        if (! $this->exists()) {
            DB::connection('mysuncash')->statement('ALTER TABLE webpos_transaction ADD INDEX idxTypeStatusDate (transaction_type, status, transaction_date)');
        }
    }

    public function down(): void
    {
        if ($this->exists()) {
            DB::connection('mysuncash')->statement('ALTER TABLE webpos_transaction DROP INDEX idxTypeStatusDate');
        }
    }
};
