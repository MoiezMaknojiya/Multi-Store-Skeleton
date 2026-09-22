<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The fonts the Ad Builder has downloaded and now hosts itself (docs/AD-BUILDER-SPEC.md §7a, config/fonts.php).
 *
 * Not store-scoped, deliberately: a font file is not anybody's content — it is a typeface the whole
 * installation may set text in, like a colour. What IS store-scoped is the design that names it.
 *
 * A row exists only once the files are on disk, so its presence is the answer to "can a television
 * render this without the internet?".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('builder_fonts', function (Blueprint $table) {
            $table->id();

            // Exactly as Google spells it ("Playfair Display"), because that is what a document's
            // `fontFamily` holds and what the compiled page's @font-face has to say.
            $table->string('family', 120)->unique();
            $table->string('slug', 140)->unique();      // playfair-display
            $table->string('kind', 20)->default('sans'); // sans | serif | display | mono | urdu

            // The weights that were actually fetched, and the file each one landed in — keyed by
            // weight, so the panel can offer only the weights this installation really has.
            $table->json('weights');
            $table->json('files');

            // The little stylesheet written beside the files: the editor loads it, and AdFontEmbedder
            // reads it to carry the files inside a published page (§7a). Kept as a path so a move of
            // the disk cannot strand it.
            $table->string('css_path');

            $table->unsignedBigInteger('size')->default(0);
            $table->foreignId('installed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('builder_fonts');
    }
};
