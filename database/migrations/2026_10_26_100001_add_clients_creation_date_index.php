<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Reports > Client Summary" filters registrations by `clients.creation_date`
 * (a VARCHAR day-prefixed timestamp) — no index covered it, so every dated
 * search scanned the whole clients table. Status already has idxClientStatusId.
 */
return new class extends Migration
{
    private function exists(): bool
    {
        return DB::connection('mysuncash')->table('information_schema.statistics')
            ->where('table_schema', DB::connection('mysuncash')->getDatabaseName())
            ->where('table_name', 'clients')->where('index_name', 'idxCreationDate')->exists();
    }

    public function up(): void
    {
        if (! $this->exists()) {
            DB::connection('mysuncash')->statement('ALTER TABLE clients ADD INDEX idxCreationDate (creation_date)');
        }
    }

    public function down(): void
    {
        if ($this->exists()) {
            DB::connection('mysuncash')->statement('ALTER TABLE clients DROP INDEX idxCreationDate');
        }
    }
};
