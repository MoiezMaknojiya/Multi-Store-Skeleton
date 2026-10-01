<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Channels: ads an organization may put on a screen as ONE line of its playlist. The line expands, exactly where
     * it stands, into whatever the channel is running that day, so an ad changed once reaches every screen carrying
     * the channel. A channel is the platform's — made above the organizations and offered to every one — or an
     * organization's own, made inside it for its own screens.
     */
    public function up(): void
    {
        // Already there on a database the earlier migrations built.
        if (Schema::hasTable('channels')) {
            return;
        }

        Schema::create('channels', function (Blueprint $table) {
            $table->id();

            // NULL: the platform's channel. Set: that organization's own channel — and it goes with it.
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();

            // Not unique: a name only has to stand apart within what one organization's Channels box lists — the
            // platform's channels and its own — and ChannelRequest checks exactly that.
            $table->string('name', 120);

            // How many of its ads play each time a screen's loop reaches the channel.
            // NULL means all of them; a smaller number rotates through the rest on the
            // passes that follow, so a long channel cannot swallow an organization's own loop.
            $table->unsignedSmallInteger('ads_per_pass')->nullable();

            // Paused rather than deleted: deleting takes the channel off every playlist
            // carrying it, and no organization can undo that.
            $table->boolean('is_active')->default(true);

            // NULL on delete, deliberately: a channel belongs to the platform or its organization, not to the person
            // who made it, so it outlives them (owner's decision).
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channels');
    }
};
