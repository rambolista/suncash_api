<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Tools > Feature Release Management". Both tables had only a PRIMARY KEY:
 * `feature_release_control.feature_type` is checked with a full scan on
 * every single Add/Edit (the duplicate-feature-type guard), and
 * `feature_release_islands.release_id` is looked up once per "specific"
 * scope release on every list load. Trivial at today's row counts (1 / 6),
 * but both are exactly the kind of unindexed lookup that's silently O(n)
 * from day one — cheap to fix now, before it's a real table.
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
        if (! $this->indexExists('feature_release_control', 'idxFeatureType')) {
            DB::connection('mysuncash')->statement(
                'ALTER TABLE feature_release_control ADD UNIQUE INDEX idxFeatureType (feature_type)'
            );
        }

        if (! $this->indexExists('feature_release_islands', 'idxReleaseId')) {
            DB::connection('mysuncash')->statement(
                'ALTER TABLE feature_release_islands ADD INDEX idxReleaseId (release_id)'
            );
        }
    }

    public function down(): void
    {
        if ($this->indexExists('feature_release_control', 'idxFeatureType')) {
            DB::connection('mysuncash')->statement(
                'ALTER TABLE feature_release_control DROP INDEX idxFeatureType'
            );
        }

        if ($this->indexExists('feature_release_islands', 'idxReleaseId')) {
            DB::connection('mysuncash')->statement(
                'ALTER TABLE feature_release_islands DROP INDEX idxReleaseId'
            );
        }
    }
};
