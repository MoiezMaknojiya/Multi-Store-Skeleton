<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every screen fades, and nobody chooses it (owner, 2026-11-04 — "by default sub per fade hi chiya tha aur woo field hata
 * deni thi"): the Transition field is gone from the Edit Screen dialog, and with it the column that kept it. The player
 * fades every switch with nothing said.
 *
 * Taken back, the column comes again with Fade for every screen, which is what each of them did.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Harmless on a database that has it gone already, as every file here is (MigrationsTest).
        if (! Schema::hasColumn('screens', 'transition')) {
            return;
        }

        Schema::table('screens', function (Blueprint $table) {
            $table->dropColumn('transition');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('screens', 'transition')) {
            return;
        }

        Schema::table('screens', function (Blueprint $table) {
            $table->string('transition', 16)->default('fade')->after('orientation');
        });
    }
};
