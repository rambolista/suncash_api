<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Tools > Customer Management > View ComplyAdvantage Profile" was
 * throwing in production: `Unknown column 'workflow_instance_identifier'
 * in 'INSERT INTO'` on `comply_error_logs`. `ComplyAdvantageService`
 * writes to this column whenever a ComplyAdvantage API call fails, to
 * record which workflow (if any) the failure happened against — dev's
 * copy of this legacy table already has it, production's doesn't (the
 * same kind of schema drift as `wu_uploaded_request`'s missing primary
 * key). Guarded so it's a no-op wherever the column already exists.
 */
return new class extends Migration
{
    private function hasColumn(string $table, string $column): bool
    {
        return DB::connection('mysuncash')->table('information_schema.columns')
            ->where('table_schema', DB::connection('mysuncash')->getDatabaseName())
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->exists();
    }

    public function up(): void
    {
        if (! $this->hasColumn('comply_error_logs', 'workflow_instance_identifier')) {
            DB::connection('mysuncash')->statement(
                'ALTER TABLE comply_error_logs ADD COLUMN workflow_instance_identifier VARCHAR(100) NULL AFTER customer_id'
            );
        }
    }

    public function down(): void
    {
        if ($this->hasColumn('comply_error_logs', 'workflow_instance_identifier')) {
            DB::connection('mysuncash')->statement(
                'ALTER TABLE comply_error_logs DROP COLUMN workflow_instance_identifier'
            );
        }
    }
};
