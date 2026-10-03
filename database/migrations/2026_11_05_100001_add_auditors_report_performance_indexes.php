<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Reports > Auditor's Report" runs ~60 queries; EXPLAIN on a month's window showed these tables read in full, or joined
 * on a column with no index:
 *  - date windows with nothing to range on: billpay_web_transactions (only status + date), webpos_transaction_kiosk (same),
 *    webpos_transaction_chp, merchant_vouchers.create_date, mobile_topup_transactions (only source/ezkard first),
 *    customer_transaction_histories (WU and Gaming Funds fee lines), gaming_house_transaction_histories,
 *    business_bill_transaction (source + status + date);
 *  - joins: customers.customer_tag and cenpos_logs4.reference_number (the card reports).
 */
return new class extends Migration
{
    private const INDEXES = [
        'billpay_web_transactions' => ['idxTransactionDate' => 'transaction_date'],
        'business_bill_transaction' => ['idxSourceStatusDate' => 'source, status, transaction_date'],
        'webpos_transaction_kiosk' => ['idxTransactionDate' => 'transaction_date'],
        'webpos_transaction_chp' => ['idxTransactionDate' => 'transaction_date'],
        'cenpos_logs4' => ['idxReferenceNumber' => 'reference_number'],
        'customers' => ['idxCustomerTag' => 'customer_tag'],
        'merchant_vouchers' => ['idxCreateDate' => 'create_date'],
        'mobile_topup_transactions' => ['idxTransactionDate' => 'transaction_date'],
        'gaming_house_transaction_histories' => ['idxTypeCreatedDate' => 'transaction_type, created_date'],
        'customer_transaction_histories' => ['idxTypeStatusCreatedDate' => 'transaction_type, status, created_date', 'idxCreatedDate' => 'created_date'],
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
