<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which platform file an organization's file was copied from (owner, 2026-10-07: Premium Templates, docs/AD-BUILDER-SPEC.md,
 * the addendum of that day): a template used twice, or two templates naming one picture, copy it once. NULL for a file
 * the organization uploaded, and again once the platform's file is deleted — the copy stays the organization's.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Harmless on a database that has it already, as every file here is (MigrationsTest).
        if (Schema::hasColumn('builder_assets', 'copied_from_id')) {
            return;
        }

        Schema::table('builder_assets', function (Blueprint $table) {
            $table->foreignId('copied_from_id')->nullable()->after('organization_id')
                ->constrained('builder_assets')->nullOnDelete();
            $table->index(['organization_id', 'copied_from_id']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('builder_assets', 'copied_from_id')) {
            return;
        }

        Schema::table('builder_assets', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'copied_from_id']);
            $table->dropConstrainedForeignId('copied_from_id');
        });
    }
};
