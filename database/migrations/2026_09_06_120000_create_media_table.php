<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();

            // Per-store isolation: a media file belongs to the store it was uploaded
            // in and is invisible from any other one. NOT nullable — there is no
            // store-less library, so an upload always needs a store context.
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->text('description')->nullable();

            // What the player needs to know to render it.
            $table->string('type', 10);                 // image | video
            $table->string('mime_type', 150);
            $table->string('disk', 30)->default('public');
            $table->string('path');                     // original file
            $table->string('thumbnail_path')->nullable();
            $table->unsignedBigInteger('size');          // bytes
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();  // videos only
            $table->string('orientation', 10)->nullable();            // landscape | portrait

            // First scheduling layer: the file itself may be dormant or expired.
            // Both null means "always eligible".
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The library is always read one store at a time, newest first.
            $table->index(['store_id', 'created_at']);
            // Read by the schedule: a file outside this window never reaches a screen and the scheduling filter.
            $table->index(['store_id', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
