<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Reports > Void" reads voided ledger rows: `trans_status_id = 1` (778 of 70k)
 * plus, depending on the tab, a transaction type and a timestamp range.
 * `ezkard_transactions` had no index on either status or type alone, so every
 * run scanned the whole table. `timestamp` is a VARCHAR(750); the index uses a
 * 24-char prefix (a date-time is 19).
 */
return new class extends Migration
{
    private function exists(): bool
    {
        return DB::connection('mysuncash')->table('information_schema.statistics')
            ->where('table_schema', DB::connection('mysuncash')->getDatabaseName())
            ->where('table_name', 'ezkard_transactions')->where('index_name', 'idxStatusTypeTimestamp')->exists();
    }

    public function up(): void
    {
        if (! $this->exists()) {
            DB::connection('mysuncash')->statement('ALTER TABLE ezkard_transactions ADD INDEX idxStatusTypeTimestamp (trans_status_id, trans_type_id, `timestamp`(24))');
        }
    }

    public function down(): void
    {
        if ($this->exists()) {
            DB::connection('mysuncash')->statement('ALTER TABLE ezkard_transactions DROP INDEX idxStatusTypeTimestamp');
        }
    }
};
