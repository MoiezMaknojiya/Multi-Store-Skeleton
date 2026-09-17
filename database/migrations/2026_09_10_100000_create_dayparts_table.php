<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dayparts', function (Blueprint $table) {
            $table->id();

            // A daypart is shop inventory, like a media file: it belongs to the store
            // it was built in and is invisible from any other one. NOT nullable —
            // "Deli hours" only means something inside one shop.
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();

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

            // One "Deli hours" per shop — two would be indistinguishable in a picker.
            $table->unique(['store_id', 'name']);
            // Every listing and picker reads one store's live dayparts.
            $table->index(['store_id', 'is_retired']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dayparts');
    }
};
