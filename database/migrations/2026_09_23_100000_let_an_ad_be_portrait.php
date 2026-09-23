<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An ad says which way the screen it is for is mounted (owner, 2026-09-23 — docs/AD-BUILDER-SPEC.md §12):
 * landscape, 1920 × 1080, or portrait, 1080 × 1920 — chosen when the ad is made and never changed after.
 *
 * Every ad made before this column existed was 1920 × 1080 (the request refused any other size), so the
 * default names them all correctly and nothing is backfilled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('builder_ads', function (Blueprint $table) {
            $table->string('orientation', 10)->default('landscape')->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('builder_ads', function (Blueprint $table) {
            $table->dropColumn('orientation');
        });
    }
};
