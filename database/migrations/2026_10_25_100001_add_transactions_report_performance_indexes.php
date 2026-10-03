<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Reports > Transactions" — every query is `merchant_id = ? AND <date range>`
 * on `webpos_transaction`, but its indexes were all single-column (merchant_id
 * has ~34 distinct values, so it narrows almost nothing; the planner picked
 * idxTransactionDate and filtered merchant per row). A (merchant_id,
 * transaction_date) composite serves the summary aggregate, the void
 * lookups and the date-ordered detail lists in one index range.
 *
 * The rest are lookups that had no index at all:
 *  - merchant_terminal_users: the User/Branch dropdowns filter `merchant_id`
 *    (a VARCHAR column) and `branch_id` — full scan of every cashier before.
 *  - billpay_web_transactions.transaction_id: the Billpay detail LEFT JOINs
 *    on it (only (status, transaction_date) existed -> scan per row).
 *  - wu_eod_webpos: filtered by merchant + timestamp range, PK only.
 *  - batch_voucher_generation.voucher_number: each voucher row LEFT JOINs it (EXPLAIN: a full 7.5k-row block-nested-loop scan PER voucher).
 *  - merchant_vouchers.voucher_date / universal_vouchers_logs.universal_vouchers_id:
 *    the voucher summaries range-filter the former and INNER JOIN the latter.
 */
return new class extends Migration
{
    private const INDEXES = [
        'webpos_transaction' => ['idxMerchantTransactionDate' => 'merchant_id, transaction_date'],
        'merchant_terminal_users' => ['idxMerchantBranch' => 'merchant_id, branch_id'],
        'billpay_web_transactions' => ['idxTransactionId' => 'transaction_id'],
        'wu_eod_webpos' => ['idxMerchantTimestamp' => 'merchant_id, timestamp'],
        'merchant_vouchers' => ['idxVoucherDate' => 'voucher_date'],
        'batch_voucher_generation' => ['idxVoucherNumber' => 'voucher_number'],
        'universal_vouchers_logs' => ['idxUniversalVouchersId' => 'universal_vouchers_id'],
    ];

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
