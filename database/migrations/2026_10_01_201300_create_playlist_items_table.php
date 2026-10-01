<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A playlist line is one of two things: a file from the organization's own library, or a channel. Exactly one of
     * the two is set. That is enforced where a playlist is written (PlaylistController) — a CHECK constraint is not
     * something the schema builder can express on every database this runs on.
     */
    public function up(): void
    {
        // Already there on a database the earlier migrations built.
        if (Schema::hasTable('playlist_items')) {
            return;
        }

        Schema::create('playlist_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('screen_id')->constrained()->cascadeOnDelete();
            // Deleting a file takes it off every screen it was playing on, which is
            // what the person deleting it expects.
            $table->foreignId('media_id')->nullable()->constrained()->cascadeOnDelete();
            // Deleting a channel takes it off every playlist carrying it.
            $table->foreignId('channel_id')->nullable()->constrained()->cascadeOnDelete();

            // A playlist is an ordered list; position is its only ordering.
            $table->unsignedInteger('position');

            // How long this ITEM shows — the same file can run 10 seconds on one
            // screen and 30 on another. Videos default to their own length and the
            // player advances when the video actually ends. A channel line has none:
            // it lasts as long as the ads it is running that day.
            $table->unsignedInteger('duration_seconds')->nullable();

            $table->timestamps();

            $table->index(['screen_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playlist_items');
    }
};
