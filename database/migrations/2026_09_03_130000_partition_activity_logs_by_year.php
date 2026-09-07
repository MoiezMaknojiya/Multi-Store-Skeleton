<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Partitions activity_logs by YEAR(created_at) so a whole year of audit data
     * can be dropped in one instant DROP PARTITION (the "older than 2 years"
     * lifecycle, driven from the Activity Log page).
     *
     * MySQL-only by nature: SQLite (the test databases) has no partitioning, and
     * the feature is transparent to queries, so other drivers simply skip this.
     *
     * Structural consequences on MySQL, all deliberate:
     *  - created_at becomes DATETIME (partitioning on TIMESTAMP is disallowed —
     *    MySQL error 1486 — because it is timezone-dependent).
     *  - The partition key must be part of the primary key → PK becomes (id, created_at).
     *  - Partitioned tables cannot carry FOREIGN KEYs → the actor_id FK is dropped.
     *    Display never depended on it (actor_name is snapshotted at write time).
     *
     * Every step is guarded so a partially-applied earlier run can be retried.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        // 1. Drop the FK if it is still there.
        $fk = DB::selectOne(
            "SELECT 1 AS found FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_logs'
               AND CONSTRAINT_NAME = 'activity_logs_actor_id_foreign' AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
        );
        if ($fk) {
            DB::statement('ALTER TABLE activity_logs DROP FOREIGN KEY activity_logs_actor_id_foreign');
        }

        // 2. Composite PK (id, created_at) if not already in place.
        $pkHasCreatedAt = DB::selectOne(
            "SELECT 1 AS found FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_logs'
               AND CONSTRAINT_NAME = 'PRIMARY' AND COLUMN_NAME = 'created_at'"
        );
        if (! $pkHasCreatedAt) {
            DB::statement('ALTER TABLE activity_logs DROP PRIMARY KEY, ADD PRIMARY KEY (id, created_at)');
        }

        // 3. DATETIME partition key (YEAR() over TIMESTAMP is rejected by MySQL).
        DB::statement('ALTER TABLE activity_logs MODIFY created_at DATETIME NOT NULL');

        // 4. Partition, unless already partitioned.
        $partitioned = DB::selectOne(
            "SELECT 1 AS found FROM information_schema.PARTITIONS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_logs' AND PARTITION_NAME IS NOT NULL LIMIT 1"
        );
        if (! $partitioned) {
            // 3 years ahead (current + next two), matching ActivityLogPartitioner::maintain().
            $year = now()->year;
            $defs = implode(', ', array_map(
                fn (int $y) => "PARTITION p{$y} VALUES LESS THAN (".($y + 1).')',
                [$year, $year + 1, $year + 2]
            ));
            DB::statement(
                'ALTER TABLE activity_logs PARTITION BY RANGE (YEAR(created_at)) ('
                .$defs.', PARTITION pmax VALUES LESS THAN MAXVALUE'
                .')'
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE activity_logs REMOVE PARTITIONING');
        DB::statement('ALTER TABLE activity_logs DROP PRIMARY KEY, ADD PRIMARY KEY (id)');
        DB::statement('ALTER TABLE activity_logs MODIFY created_at TIMESTAMP NOT NULL');
        // Users deleted while the FK was absent leave stale actor_ids; null them
        // first, otherwise re-adding the FK fails (errno 1452) and leaves a broken
        // half-reverted schema. (Subquery is on a different table — allowed.)
        DB::statement('UPDATE activity_logs SET actor_id = NULL WHERE actor_id IS NOT NULL AND actor_id NOT IN (SELECT id FROM users)');
        DB::statement('ALTER TABLE activity_logs ADD CONSTRAINT activity_logs_actor_id_foreign FOREIGN KEY (actor_id) REFERENCES users (id) ON DELETE SET NULL');
    }
};
