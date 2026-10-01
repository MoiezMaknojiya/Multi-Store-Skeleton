<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An ad of a channel. A channel keeps no files of its own (docs/CHANNEL-CONTENT-SPEC.md): each ad holds a row of a
     * media library — its organization's for an organization's channel, the platform's for the platform's — so an Ad
     * Builder page published again is what the channel shows next.
     */
    public function up(): void
    {
        // Already there on a database the earlier migrations built.
        if (Schema::hasTable('channel_ads')) {
            return;
        }

        Schema::create('channel_ads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            // The file. Deleting it from its library takes the ad out of the channel — which is why the library
            // refuses to delete a file a channel still shows.
            $table->foreignId('media_id')->constrained('media')->cascadeOnDelete();

            $table->string('title');

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
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_ads');
    }
};
