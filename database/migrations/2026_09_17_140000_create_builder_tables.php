<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Ad Builder (docs/AD-BUILDER-SPEC.md): a store designs a 1920×1080 advert on a fixed stage, and
 * publishing it writes a self-contained HTML file plus a `media` row of type `html` — which is how it
 * reaches a playlist, a schedule and a television without any of those learning a new trick.
 *
 * Two tables: the ad (its editable document) and the Builder's own shelf of images and videos. The shelf
 * is deliberately NOT the store's media library (owner's decision, 2026-09-17): what goes INSIDE an ad is
 * the builder's raw material, while the library is what a shop plays.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('builder_ads', function (Blueprint $table) {
            $table->id();

            // An ad always belongs to one store — the platform builds FOR a store, never above them all —
            // because the media row it publishes into cannot be store-less either.
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();

            $table->string('name');

            // The design itself: the stage's background layers and the ordered elements. The editor reads
            // and writes this; publishing compiles it. See §6 of the spec for its shape.
            $table->json('document');

            // Captured in the browser when the ad is saved, so a listing and the playlist picker can show
            // the ad rather than a grey box. There is no headless browser on the server to render one.
            $table->string('thumbnail_path')->nullable();

            // The published copy a playlist points at. NULL until the first publish; nulled — not
            // cascaded — if that media row is deleted from the library, so the design itself survives.
            $table->foreignId('media_id')->nullable()->constrained('media')->nullOnDelete();

            // NULL means "never published, or edited since" — the listing says Draft for both.
            $table->timestamp('published_at')->nullable();

            // The ad belongs to its store, not to whoever drew it, so it outlives them.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['store_id', 'updated_at']);
        });

        Schema::create('builder_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();

            $table->string('title');
            $table->string('kind', 10);                    // image | video
            $table->string('mime_type', 150);
            $table->string('disk', 30)->default('public');
            $table->string('path');                        // builder/{store}/assets/…
            $table->string('thumbnail_path')->nullable();
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();

            // A video's own length, measured in the browser at upload (there is no ffmpeg).
            $table->unsignedInteger('duration_seconds')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['store_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('builder_assets');
        Schema::dropIfExists('builder_ads');
    }
};
