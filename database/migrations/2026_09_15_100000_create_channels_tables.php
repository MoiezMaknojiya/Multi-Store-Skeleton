<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Channels: ads a shop may put on a screen as ONE line of its playlist. The line expands, exactly where it
     * stands, into whatever the channel is running that day, so an ad changed once reaches every screen carrying
     * the channel. A channel is the platform's — made above the stores and offered to every shop — or a store's
     * own, made inside that store for its own screens.
     */
    public function up(): void
    {
        Schema::create('channels', function (Blueprint $table) {
            $table->id();

            // NULL: the platform's channel. Set: that store's own channel — and it goes with the store.
            $table->foreignId('store_id')->nullable()->constrained()->cascadeOnDelete();

            // Not unique: a name only has to stand apart within what one store's Channels box lists — the
            // platform's channels and the store's own — and ChannelRequest checks exactly that.
            $table->string('name', 120);

            // How many of its ads play each time a screen's loop reaches the channel.
            // NULL means all of them; a smaller number rotates through the rest on the
            // passes that follow, so a long channel cannot swallow a shop's own loop.
            $table->unsignedSmallInteger('ads_per_pass')->nullable();

            // Paused rather than deleted: deleting takes the channel off every playlist
            // carrying it, and no shop can undo that.
            $table->boolean('is_active')->default(true);

            // NULL on delete, deliberately: a channel belongs to the platform or its store, not to the person who
            // made it, so it outlives them (owner's decision).
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('channel_ads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();

            $table->string('title');

            // ── The file: the same shape a media row keeps ─────────────────────────
            $table->string('type', 10);                    // image | video
            $table->string('mime_type', 150);
            $table->string('disk', 30)->default('public');
            $table->string('path');
            $table->string('thumbnail_path')->nullable();
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('orientation', 10)->nullable(); // landscape | portrait

            // A video's own length, measured in the browser at upload (there is no ffmpeg).
            $table->unsignedInteger('media_duration_seconds')->nullable();
            // How long an IMAGE stays up. A video has no such setting at all — it runs to
            // its own end (owner's decision) — so this stays NULL for one.
            $table->unsignedInteger('duration_seconds')->nullable();

            // The order the ads play in, inside the channel.
            $table->unsignedInteger('position')->default(0);

            // Both NULL means "until it is taken out". Read on each screen's own calendar.
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['channel_id', 'position']);
        });

        // A playlist line may be a channel. Added here rather than with playlist_items, because the table it
        // points at only exists from this migration on. Deleting a channel takes it off every playlist carrying it.
        Schema::table('playlist_items', function (Blueprint $table) {
            $table->foreignId('channel_id')->nullable()->after('media_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('playlist_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('channel_id');
        });

        Schema::dropIfExists('channel_ads');
        Schema::dropIfExists('channels');
    }
};
