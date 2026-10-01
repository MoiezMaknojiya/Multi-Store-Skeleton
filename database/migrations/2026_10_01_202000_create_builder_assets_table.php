<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Ad Builder's own shelf of pictures and videos. Deliberately NOT the media library (owner's decision,
 * 2026-09-17): what goes INSIDE an ad is the builder's raw material, while the library is what a screen plays.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Already there on a database the earlier migrations built.
        if (Schema::hasTable('builder_assets')) {
            return;
        }

        Schema::create('builder_assets', function (Blueprint $table) {
            $table->id();
            // The organization whose shelf it is on — and it goes with it. NULL is the platform's, shared with every
            // organization: their designs may use it, and only the platform deletes it.
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->string('kind', 10);                    // image | video
            $table->string('mime_type', 150);
            $table->string('disk', 30)->default('public');
            $table->string('path');                        // builder/{organization}/assets/… or builder/platform/assets/…
            $table->string('thumbnail_path')->nullable();
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();

            // A video's own length, read by the server from the file.
            $table->unsignedInteger('duration_seconds')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('builder_assets');
    }
};
