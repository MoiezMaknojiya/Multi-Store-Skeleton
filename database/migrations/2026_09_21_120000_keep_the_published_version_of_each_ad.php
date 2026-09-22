<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An ad keeps the version that is on the screens (docs/AD-BUILDER-SPEC.md §9, owner 2026-09-21: "industry
 * standard"): a published ad that is changed keeps playing what was published until the changes are published
 * — the way Xibo, Contentful and Strapi work — and "Discard changes" goes back to that version, so it has to
 * be kept somewhere. Publish writes it; nothing else does.
 *
 * An ad published and unchanged since is on the screens exactly as it is stored, so that is its published
 * version. One changed after its publish, before this existed, has none to keep: it stays on the screens,
 * marked as changed, and its next Publish keeps one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('builder_ads', function (Blueprint $table) {
            $table->json('published_document')->nullable()->after('published_at');
            $table->string('published_name')->nullable()->after('published_document');
        });

        DB::table('builder_ads')
            ->whereNotNull('published_at')
            ->whereColumn('published_at', '>=', 'updated_at')
            ->update(['published_document' => DB::raw('document'), 'published_name' => DB::raw('name')]);
    }

    public function down(): void
    {
        Schema::table('builder_ads', function (Blueprint $table) {
            $table->dropColumn(['published_document', 'published_name']);
        });
    }
};
