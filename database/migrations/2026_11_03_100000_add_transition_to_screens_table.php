<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a screen changes from one item to the next (owner, 2026-11-02 — "fade setting mein rakho"): `none`, a swap the
 * moment the next item is ready, or `fade`, the next item fading in over the one before. Every screen starts with none:
 * a screen paired before plays exactly as it did.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Harmless on a database that has it already, as every file here is (MigrationsTest).
        if (Schema::hasColumn('screens', 'transition')) {
            return;
        }

        Schema::table('screens', function (Blueprint $table) {
            $table->string('transition', 16)->default('none')->after('orientation');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('screens', 'transition')) {
            return;
        }

        Schema::table('screens', function (Blueprint $table) {
            $table->dropColumn('transition');
        });
    }
};
