<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Merchant Settlements (`manual_settlement`) had zero indexes beyond the
 * PRIMARY key. Two access patterns need one each:
 *  - the tab list: WHERE status = ? ORDER BY created_date DESC
 *  - per-merchant averages (getDetail): WHERE client_record_id = ? ORDER BY created_date
 * Same fix already applied to customer_settlements' equivalent indexes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $indexes = [
            'manual_settlement_status_created_date_index' => '(status, created_date)',
            'manual_settlement_client_record_id_created_date_index' => '(client_record_id, created_date)',
        ];

        foreach ($indexes as $name => $columns) {
            if (! $this->indexExists('manual_settlement', $name)) {
                DB::connection('mysuncash')->statement("ALTER TABLE manual_settlement ADD INDEX {$name} {$columns}");
            }
        }
    }

    public function down(): void
    {
        foreach (['manual_settlement_status_created_date_index', 'manual_settlement_client_record_id_created_date_index'] as $name) {
            if ($this->indexExists('manual_settlement', $name)) {
                DB::connection('mysuncash')->statement("ALTER TABLE manual_settlement DROP INDEX {$name}");
            }
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        return DB::connection('mysuncash')->table('information_schema.statistics')
            ->where('table_schema', DB::connection('mysuncash')->getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }
};
