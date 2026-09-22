<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Promotions > Ticket Reports (`promo_entries`, 45k+ rows) had zero indexes
 * beyond the PRIMARY key, and the date-range filter was wrapped in
 * DATE(pe.create_date) (fixed in PromoTicketReportService), which on its own
 * would have defeated any index anyway. Every query — including the default
 * "today" view — did a full table scan (EXPLAIN: type=ALL, rows=45385,
 * Using filesort). A plain index on create_date lets the range filter use
 * an index range scan instead, and filesort only the (much smaller) matched
 * rows rather than the whole table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->indexExists('promo_entries', 'promo_entries_create_date_index')) {
            DB::connection('mysuncash')->statement('ALTER TABLE promo_entries ADD INDEX promo_entries_create_date_index (create_date)');
        }
    }

    public function down(): void
    {
        if ($this->indexExists('promo_entries', 'promo_entries_create_date_index')) {
            DB::connection('mysuncash')->statement('ALTER TABLE promo_entries DROP INDEX promo_entries_create_date_index');
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
