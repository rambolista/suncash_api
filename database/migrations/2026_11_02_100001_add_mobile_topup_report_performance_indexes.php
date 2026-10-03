<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Reports > Mobile Top Up" read `mobile_topup_transactions` and `webpos_transaction_3rdparty` through their primary
 * keys only: every search scanned all top-ups, and each WebPOS merchant top-up found its 3rd-party row by scanning
 * every row of that table (`trans_ref_id` is a VARCHAR compared to the numeric top-up id, so an index on it alone can't
 * be used — the `transaction_type` prefix lets the join start from just the 'topup' rows and walk the top-up primary key).
 * `ezkard_id` serves the customer-account filter and the Account No. dropdown.
 */
return new class extends Migration
{
    private const INDEXES = [
        'mobile_topup_transactions' => [
            'idxSourceDate' => 'source, transaction_date',
            'idxEzkardDate' => 'ezkard_id, transaction_date',
        ],
        'webpos_transaction_3rdparty' => ['idxTypeRefMerchant' => 'transaction_type, trans_ref_id, merchant_id'],
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
