<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Reports > Voucher" windows the list by `voucher_date` (indexed already) — or by the redeem/void date `update_date`
 * when Status is Redeemed/Voided or only a Redeemed Source is picked, which had no index (a full scan + filesort of
 * every voucher). The merchant filters hit `purchased_client_id` / `redeemed_client_id` on universal_vouchers, also
 * unindexed. The Purchased/Redeemed Source dropdowns list clients holding the SUNCASHVOUCHER service, joining
 * `services_permission` (primary key only) by block-nested-loop.
 */
return new class extends Migration
{
    private const INDEXES = [
        'merchant_vouchers' => [
            'idxUpdateDate' => 'update_date',
            'idxStatusUpdateDate' => 'status, update_date',
        ],
        'universal_vouchers' => [
            'idxUpdateDate' => 'update_date',
            'idxStatusUpdateDate' => 'status, update_date',
            'idxPurchasedClient' => 'purchased_client_id',
            'idxRedeemedClient' => 'redeemed_client_id',
        ],
        'services_permission' => ['idxServiceStatusClient' => 'system_services_id, status, client_record_id'],
    ];

    private function indexExists(string $table, string $indexName): bool
    {
        return DB::connection('mysuncash')->table('information_schema.statistics')
            ->where('table_schema', DB::connection('mysuncash')->getDatabaseName())
            ->where('table_name', $table)->where('index_name', $indexName)->exists();
    }

    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach ($indexes as $name => $columns) {
                if (! $this->indexExists($table, $name)) {
                    DB::connection('mysuncash')->statement("ALTER TABLE {$table} ADD INDEX {$name} ({$columns})");
                }
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            foreach (array_keys($indexes) as $name) {
                if ($this->indexExists($table, $name)) {
                    DB::connection('mysuncash')->statement("ALTER TABLE {$table} DROP INDEX {$name}");
                }
            }
        }
    }
};
