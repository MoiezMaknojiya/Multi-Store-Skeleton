<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Audit trail: who did what, when. The actor's name is snapshotted so the
     * log stays readable even after the actor is deleted.
     */
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name');
            // The store an entry belongs to, so a store's own people read their store's history and nothing else.
            // NULL for the platform's own work and a person's own account. No foreign key: the table is partitioned
            // on MySQL, which allows none, and a store's history outlives the store.
            $table->unsignedBigInteger('store_id')->nullable();
            $table->string('action', 100)->index();
            $table->string('subject_type', 100)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('description', 1000)->nullable();
            $table->timestamp('created_at')->index();

            // Leads with the store because a store's reader always filters on it, then on the date range that
            // prunes the yearly partitions.
            $table->index(['store_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
