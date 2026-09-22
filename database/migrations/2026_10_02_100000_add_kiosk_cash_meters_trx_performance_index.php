<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Kiosk > Cash Meters (Transaction)" — `KioskCashMeterService::meters()`
 * runs `WHERE terminal_id = ? AND type = ? ... ORDER BY id DESC LIMIT 1`
 * against `kiosk_cash_meters_trx`, which has no index besides its own
 * primary key. Same trap as the earlier `ezkard_transactions` fix: MySQL
 * walks the PRIMARY KEY (id) in reverse looking for the first row
 * matching this terminal+type, so the cost scales with how far back that
 * terminal's latest reading sits from the table's newest row overall, not
 * with the actual amount of matching data. Confirmed live: a terminal
 * whose latest matching row was 1397 rows behind the table's max id still
 * required scanning all of them.
 *
 * A composite index on (terminal_id, type, id) lets this resolve as a
 * direct index seek to that terminal+type's rows in id order, independent
 * of every other terminal's activity.
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
        if (! $this->indexExists('kiosk_cash_meters_trx', 'idxTerminalIdTypeId')) {
            DB::connection('mysuncash')->statement(
                'ALTER TABLE kiosk_cash_meters_trx ADD INDEX idxTerminalIdTypeId (terminal_id, type, id)'
            );
        }
    }

    public function down(): void
    {
        if ($this->indexExists('kiosk_cash_meters_trx', 'idxTerminalIdTypeId')) {
            DB::connection('mysuncash')->statement(
                'ALTER TABLE kiosk_cash_meters_trx DROP INDEX idxTerminalIdTypeId'
            );
        }
    }
};
