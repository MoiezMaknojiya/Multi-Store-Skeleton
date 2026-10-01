<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A device that has asked for a code but has not been claimed yet. Kept out of `screens` on purpose: an unclaimed
     * television must never show up as a screen, and expired rows can then simply be deleted.
     */
    public function up(): void
    {
        // Already there on a database the earlier migrations built.
        if (Schema::hasTable('pairing_requests')) {
            return;
        }

        Schema::create('pairing_requests', function (Blueprint $table) {
            $table->id();
            $table->string('device_uuid', 40)->unique();
            // Human-facing: six characters, read off a TV across a room.
            $table->string('code', 6)->unique();
            // Only the device that registered may poll this request.
            $table->string('poll_secret_hash', 64);
            $table->timestamp('expires_at');

            // Set the moment somebody in the organization claims the code; the device collects the token
            // on its next poll and the row is deleted immediately after.
            $table->foreignId('claimed_screen_id')->nullable()->constrained('screens')->cascadeOnDelete();
            $table->text('claimed_token')->nullable();

            $table->timestamps();
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pairing_requests');
    }
};
