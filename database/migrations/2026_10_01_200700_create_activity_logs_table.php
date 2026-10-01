<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit trail: who did what, when. The actor's name is snapshotted so the log stays readable even after the
     * actor is deleted.
     *
     * On MySQL the table is partitioned by YEAR(created_at), so a whole year of entries can be dropped in one instant
     * DROP PARTITION (the "older than two years" lifecycle, run by the monthly schedule and the Activity Log page's
     * button — ActivityLogPartitioner). SQLite, the test databases, has no partitioning, and the feature is invisible
     * to queries, so it skips that half. What partitioning costs on MySQL, all deliberate:
     *  - created_at is a DATETIME (partitioning on a TIMESTAMP is refused — error 1486 — because it depends on the
     *    time zone);
     *  - the partition key must be part of the primary key, so the key is (id, created_at);
     *  - a partitioned table carries no foreign key, so actor_id has none there. Display never depended on it.
     */
    public function up(): void
    {
        // Already there on a database the earlier migrations built.
        if (Schema::hasTable('activity_logs')) {
            return;
        }

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name');
            // The organization an entry belongs to, so an organization's own people read its history and nothing
            // else. NULL for the platform's own work and a person's own account. No foreign key: the table is
            // partitioned on MySQL, which allows none, and an organization's history outlives it.
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->string('action', 100)->index();
            $table->string('subject_type', 100)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('description', 1000)->nullable();
            $table->timestamp('created_at')->index();

            // Leads with the organization because its reader always filters on it, then on the date range that
            // prunes the yearly partitions.
            $table->index(['organization_id', 'created_at']);
        });

        if (DB::getDriverName() === 'mysql') {
            $this->partitionByYear();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }

    private function partitionByYear(): void
    {
        DB::statement('ALTER TABLE activity_logs DROP FOREIGN KEY activity_logs_actor_id_foreign');
        DB::statement('ALTER TABLE activity_logs DROP PRIMARY KEY, ADD PRIMARY KEY (id, created_at)');
        DB::statement('ALTER TABLE activity_logs MODIFY created_at DATETIME NOT NULL');

        // Three years ahead (this one and the next two), as ActivityLogPartitioner::maintain() keeps them.
        $year = now()->year;
        $partitions = implode(', ', array_map(
            fn (int $y) => "PARTITION p{$y} VALUES LESS THAN (".($y + 1).')',
            [$year, $year + 1, $year + 2],
        ));

        DB::statement("ALTER TABLE activity_logs PARTITION BY RANGE (YEAR(created_at)) ({$partitions}, PARTITION pmax VALUES LESS THAN MAXVALUE)");
    }
};
