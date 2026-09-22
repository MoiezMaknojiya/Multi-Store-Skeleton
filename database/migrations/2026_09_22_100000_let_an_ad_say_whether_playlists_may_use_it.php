<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An ad says whether a shop's own playlist may play it (owner's rule, 2026-09-22). The same ad inside a
 * channel AND on the playlist that carries that channel plays twice in one pass, so an ad is for channels
 * only until somebody ticks "Show in playlists" in the editor.
 *
 * A new ad starts unticked. Every ad already published is ticked here: it is what the pickers offer today,
 * and a screen may already be carrying it — the rule must not take anything off a television.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('builder_ads', function (Blueprint $table) {
            $table->boolean('in_playlists')->default(false)->after('published_at');
        });

        DB::table('builder_ads')->whereNotNull('published_at')->update(['in_playlists' => true]);
    }

    public function down(): void
    {
        Schema::table('builder_ads', function (Blueprint $table) {
            $table->dropColumn('in_playlists');
        });
    }
};
