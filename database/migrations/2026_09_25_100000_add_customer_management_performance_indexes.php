<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Tools > Customer Management" → "View Details" was taking a minute or
 * more. `CustomerManagementController::show()` eagerly runs three queries
 * on every open, and two had no usable index:
 *
 * - `CustomerArchiveService::recentTransactions()` — `WHERE ezkard_id = ?
 *   ORDER BY timestamp DESC LIMIT 10`. `ezkard_id` and `timestamp` were each
 *   indexed separately, so MySQL picked the `timestamp` index and walked it
 *   backwards from "now" until it hit 10 rows for this customer (confirmed
 *   via EXPLAIN: `type: index`, scanning the whole table). For a customer
 *   whose most recent card activity is old, that's a near-full-table scan —
 *   this is almost certainly the actual "1 minute" cause, and it's data-
 *   dependent (recently-active customers looked fine, dormant ones didn't).
 * - `CustomerManagementService::averageMonthlyAmount()` — two `AVG()`
 *   queries filtering `customer_id` + `finance_orientation` + a date range;
 *   only `customer_id` was indexed, so each scanned every transaction row
 *   for that customer (measured 83ms/21ms on a 23k-row customer in dev;
 *   scales with that customer's row count, not the table's).
 *
 * `customer_notes`/`prepaid_visa_cards`/`customers_uuid` had no index on
 * `customer_id` at all (harmless at today's row counts, but the same
 * unbounded-growth trap as the two above), so those are covered too.
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

    private function addIndex(string $table, string $indexName, string $columns): void
    {
        if (! $this->indexExists($table, $indexName)) {
            DB::connection('mysuncash')->statement("ALTER TABLE {$table} ADD INDEX {$indexName} ({$columns})");
        }
    }

    public function up(): void
    {
        $this->addIndex('ezkard_transactions', 'idxEzkardIdTimestamp', 'ezkard_id, timestamp');
        $this->addIndex('customer_transaction_histories', 'idxCustomerIdOrientationCreatedDate', 'customer_id, finance_orientation, created_date, amount');
        $this->addIndex('customer_notes', 'idxCustomerIdId', 'customer_id, id');
        $this->addIndex('prepaid_visa_cards', 'idxCustomerId', 'customer_id');
        $this->addIndex('customers_uuid', 'idxCustomerId', 'customer_id');
    }

    public function down(): void
    {
        $indexes = [
            'ezkard_transactions' => 'idxEzkardIdTimestamp',
            'customer_transaction_histories' => 'idxCustomerIdOrientationCreatedDate',
            'customer_notes' => 'idxCustomerIdId',
            'prepaid_visa_cards' => 'idxCustomerId',
            'customers_uuid' => 'idxCustomerId',
        ];

        foreach ($indexes as $table => $index) {
            if ($this->indexExists($table, $index)) {
                DB::connection('mysuncash')->statement("ALTER TABLE {$table} DROP INDEX {$index}");
            }
        }
    }
};
