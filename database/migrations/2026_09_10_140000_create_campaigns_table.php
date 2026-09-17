<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A network advertisement: the PLATFORM's own content, sold to a brand and shown
 * across shops that agreed to carry it.
 *
 * It carries its own file rather than being a `media` row, on purpose. `media` is
 * "the store's library" — store_id is not nullable, every upload is stamped with a
 * store, and Media::visibleTo scopes on that store alone. A network ad belongs to
 * nobody's shop, so making room for it in that table would mean either a nullable
 * owner or a fake store, and either one puts a hole in the wall the whole app rests
 * on. A different owner gets a different table; only the upload plumbing is shared
 * (MediaStorage::storeCampaignFile).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();

            $table->string('name', 120);
            // Who the ad is FOR, as opposed to what this campaign is called. Kept
            // separate from day one so that "never run Coca-Cola in a shop that
            // sells Pepsi" is a later table and not a later migration of this one.
            $table->string('advertiser_name', 120)->nullable();

            // ── The file ──────────────────────────────────────────────────────
            $table->string('type', 10);                  // image | video
            $table->string('mime_type', 150);
            $table->string('disk', 30)->default('public');
            $table->string('path');
            $table->string('thumbnail_path')->nullable();
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            // A video's own length, measured in the browser at upload (there is no
            // ffmpeg here). Used to work out how long the ad break will run.
            $table->unsignedInteger('media_duration_seconds')->nullable();

            // How long an IMAGE ad stays on screen. A video ignores it and runs to
            // its own end, exactly like a playlist item.
            $table->unsignedInteger('duration_seconds')->default(15);

            // ── When it runs ──────────────────────────────────────────────────
            // A brand's contract always has a period; both null means "until I turn
            // it off".
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            // Optionally only inside a window — "lunchtime only" — read on each screen's own clock, with the rule
            // dayparts use: an end BEFORE the start crosses midnight. Both null means all day. Two times of its own
            // rather than a daypart: dayparts belong to one store, and a campaign spans many.
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();

            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Every manifest asks the same question: which campaigns are live?
            $table->index(['is_active', 'starts_on', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};
