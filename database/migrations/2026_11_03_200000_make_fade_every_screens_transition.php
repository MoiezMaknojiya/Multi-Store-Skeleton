<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fade is every screen's transition (owner, 2026-11-03 — "fade wala kaam ... screen par manually ON/OFF karna parta hai.
 * Isay default mein hi laga do"): a screen paired from now on fades from one item to the next, and so does every screen
 * there is; None stays a choice in the Edit Screen dialog.
 *
 * Taken back, a new screen is None again, and the screens keep what they have: which of them were None before cannot
 * be told from the ones chosen Fade by hand (one on live already), and a choice is never undone by a rollback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('screens', function (Blueprint $table) {
            $table->string('transition', 16)->default('fade')->change();
        });

        DB::table('screens')->where('transition', 'none')->update(['transition' => 'fade']);
    }

    public function down(): void
    {
        Schema::table('screens', function (Blueprint $table) {
            $table->string('transition', 16)->default('none')->change();
        });
    }
};
