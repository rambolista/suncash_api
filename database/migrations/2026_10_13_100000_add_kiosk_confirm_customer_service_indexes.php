<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Kiosk > Confirm Customer Service" listing. `KioskConfirmCustomerServiceService::list()`
 * filters `kiosk_cash_meters_trx` by `type IN ('in','out')` + a timestamp range and orders
 * by `timestamp`. The existing `idxTerminalIdTypeId (terminal_id, type, id)` index (added
 * for the Cash Meters feature) doesn't cover this: the default "all terminals" view has no
 * terminal_id filter, and none of its columns are `timestamp`. Adds a composite index
 * matching this feature's actual filter+sort shape.
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
        if (! $this->indexExists('kiosk_cash_meters_trx', 'idxTypeTimestamp')) {
            DB::connection('mysuncash')->statement(
                'ALTER TABLE kiosk_cash_meters_trx ADD INDEX idxTypeTimestamp (type, timestamp)'
            );
        }
    }

    public function down(): void
    {
        if ($this->indexExists('kiosk_cash_meters_trx', 'idxTypeTimestamp')) {
            DB::connection('mysuncash')->statement(
                'ALTER TABLE kiosk_cash_meters_trx DROP INDEX idxTypeTimestamp'
            );
        }
    }
};
