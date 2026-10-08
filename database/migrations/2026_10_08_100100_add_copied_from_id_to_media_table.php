<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which platform library row an organization's row was copied from (docs/BILLING-SPEC.md §5): when the Content Library became the
 * organization's own, every playlist line naming a platform row was given the organization's own copy, and this says which —
 * so that move can be undone, and so a copy is never made twice. NULL for everything else, and again once the platform's row is gone.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('media', 'copied_from_id')) {
            return;
        }

        Schema::table('media', function (Blueprint $table) {
            $table->foreignId('copied_from_id')->nullable()->after('organization_id')->constrained('media')->nullOnDelete();
            $table->index(['organization_id', 'copied_from_id']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('media', 'copied_from_id')) {
            return;
        }

        Schema::table('media', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'copied_from_id']);
            $table->dropConstrainedForeignId('copied_from_id');
        });
    }
};
