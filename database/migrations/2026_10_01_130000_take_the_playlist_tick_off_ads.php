<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Publish alone decides where an Ad Builder ad may be chosen (owner, 2026-10-01: "agar publish honga toh hi content
 * library aur channel mein show honga warna nahi honga, aur channel mein add ha toh content library mein show naah ho
 * usko — yeh tick wala kaam hat jayega"). The "Show in playlists" tick of 2026-09-22 kept an ad out of the playlist's
 * picker so that it could not sit in a channel and on the playlist carrying that channel; the rule of 2026-09-26 —
 * a file plays from playlists or from channels, never both — does that for every file, ads included, so the tick goes,
 * column and all. A published ad is offered to a playlist and to a channel alike, and the side that takes it first
 * keeps it from the other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('builder_ads', function (Blueprint $table) {
            $table->dropColumn('in_playlists');
        });
    }

    /**
     * Back to the tick: every ad published now is ticked, so going back takes nothing off any picker or screen that
     * shows it, and a draft starts unticked, as a new ad did.
     */
    public function down(): void
    {
        Schema::table('builder_ads', function (Blueprint $table) {
            $table->boolean('in_playlists')->default(false)->after('published_at');
        });

        DB::table('builder_ads')->whereNotNull('published_at')->update(['in_playlists' => true]);
    }
};
