<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Ad Builder (docs/AD-BUILDER-SPEC.md): an advert is designed on a fixed stage, and publishing it writes a
 * self-contained HTML file plus a `media` row of type `html` — which is how it reaches a playlist, a channel, a
 * schedule and a television without any of those learning a new trick. This is the ad: its editable design, and the
 * version that is on the screens.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Already there on a database the earlier migrations built.
        if (Schema::hasTable('builder_ads')) {
            return;
        }

        Schema::create('builder_ads', function (Blueprint $table) {
            $table->id();

            // The organization the ad is for — and it goes with it. NULL is the platform's, made for every
            // organization: each sees it once it is published and copies it into its own Ads.
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('name');

            // Which way the screen it is for is mounted: landscape, 1920 × 1080, or portrait, 1080 × 1920 — chosen when
            // the ad is made and never changed after.
            $table->string('orientation', 10)->default('landscape');

            // The design itself: the stage's background layers and the ordered elements. The editor reads
            // and writes this; publishing compiles it. See §6 of the spec for its shape.
            $table->json('document');

            // Captured in the browser when the ad is saved, so a listing and the playlist picker can show
            // the ad rather than a grey box. There is no headless browser on the server to render one.
            $table->string('thumbnail_path')->nullable();

            // The published copy a playlist points at. NULL until the first publish; nulled — not
            // cascaded — if that media row is deleted from the library, so the design itself survives.
            $table->foreignId('media_id')->nullable()->constrained('media')->nullOnDelete();

            // NULL means not on the screens: never published, or unpublished since.
            $table->timestamp('published_at')->nullable();
            // The version that is on the screens. A save changes the draft only; Publish writes this, and Discard
            // changes goes back to it.
            $table->json('published_document')->nullable();
            $table->string('published_name')->nullable();

            // The ad belongs to its organization, not to whoever drew it, so it outlives them.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('builder_ads');
    }
};
