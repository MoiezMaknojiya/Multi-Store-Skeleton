<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A television that has been paired, and what it needs to play by itself. */
    public function up(): void
    {
        // Already there on a database the earlier migrations built.
        if (Schema::hasTable('screens')) {
            return;
        }

        Schema::create('screens', function (Blueprint $table) {
            $table->id();

            // Same wall as media: a screen belongs to the organization it was paired in.
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('name');

            // The panel is always 1920x1080; the player rotates its own content,
            // so no resolution column is needed — only which way it is mounted.
            $table->string('orientation', 20)->default('landscape');

            // The clock this screen keeps. Every schedule time on its playlist is read in it, so two televisions in
            // different cities can share one "Breakfast 07:00-11:00" and each start at seven in their own morning.
            // An IANA zone, not an offset: America/Chicago covers CST and CDT by itself, so nobody has to change
            // anything twice a year.
            $table->string('timezone', 64)->default('America/Chicago');

            // What plays when nothing on the playlist is due — a gap between two schedules, or every item filtered
            // out. NULL leaves it black.
            $table->foreignId('default_media_id')->nullable()->constrained('media')->nullOnDelete();

            // Whether THIS television carries network advertising, once its organization has agreed (the
            // organization's own flag): the one over the children's tables may not, where the rest do. Off by
            // default, by the owner's decision — a television nobody chose is not a billboard. An ad reaches a screen
            // only when both flags say yes and a campaign has chosen it.
            $table->boolean('accepts_network_ads')->default(false);

            // The device's password. Only the hash is stored, like a user password:
            // the plaintext is handed to the device once, at pairing.
            $table->string('token_hash', 64)->nullable()->unique();
            $table->string('device_uuid', 40)->nullable()->index();

            $table->timestamp('paired_at')->nullable();
            $table->foreignId('paired_by')->nullable()->constrained('users')->nullOnDelete();
            // Drives the Online/Offline chip — the only thing the device reports.
            $table->timestamp('last_seen_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('screens');
    }
};
