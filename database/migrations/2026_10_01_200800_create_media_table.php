<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A media library: an organization's, or the platform's own. A file is kept to its name — when it plays is said
     * on its playlist line alone.
     */
    public function up(): void
    {
        // Already there on a database the earlier migrations built.
        if (Schema::hasTable('media')) {
            return;
        }

        Schema::create('media', function (Blueprint $table) {
            $table->id();

            // The wall: a file belongs to the organization it was uploaded in and is invisible from any other one.
            // NULL is the platform's own library — NULL rather than 0 because this column keeps its foreign key,
            // the one that takes a deleted organization's rows with it.
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('title');

            // What the player needs to know to render it.
            $table->string('type', 10);                 // image | video | html (a published Ad Builder page)
            $table->string('mime_type', 150);
            $table->string('disk', 30)->default('public');
            $table->string('path');                     // original file
            $table->string('thumbnail_path')->nullable();
            $table->unsignedBigInteger('size');          // bytes
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            // A video's own length, read by the server from the file; an Ad Builder page's, said by its design.
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('orientation', 10)->nullable();            // landscape | portrait

            // History only: the file is the organization's, not the uploader's, so it outlives them.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The library is always read one organization at a time, newest first.
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
