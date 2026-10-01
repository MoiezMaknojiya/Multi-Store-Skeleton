<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A named window of the day ("Breakfast", "Deli hours") that a playlist's schedule rules point at. */
    public function up(): void
    {
        // Already there on a database the earlier migrations built.
        if (Schema::hasTable('dayparts')) {
            return;
        }

        Schema::create('dayparts', function (Blueprint $table) {
            $table->id();

            // A daypart is an organization's inventory, like a media file: it belongs to the one
            // it was built in and is invisible from any other. NOT nullable —
            // "Deli hours" only means something inside one organization.
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('name', 100);

            // Wall-clock times, read in the SCREEN's timezone (see screens.timezone).
            // end_time BEFORE start_time means the window crosses midnight — that one
            // rule removes the need for a second window per day. The two may not be
            // equal; the request rejects it so nobody has to guess whether that means
            // zero hours or twenty-four.
            $table->time('start_time');
            $table->time('end_time');

            // Retired, never deleted while a playlist's schedule rules point at it:
            // a retired daypart disappears from new pickers and keeps working where
            // it is already in use.
            $table->boolean('is_retired')->default(false);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One "Deli hours" per organization — two would be indistinguishable in a picker.
            $table->unique(['organization_id', 'name']);
            // Every listing and picker reads one organization's live dayparts.
            $table->index(['organization_id', 'is_retired']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dayparts');
    }
};
