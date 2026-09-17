<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daypart_exceptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('daypart_id')->constrained()->cascadeOnDelete();

            // ISO weekday: 1 = Monday … 7 = Sunday, matching Carbon's dayOfWeekIso so
            // no conversion table is needed anywhere.
            $table->unsignedTinyInteger('weekday');

            // BOTH null means the daypart is closed on that weekday. That is why these
            // are nullable and the base window's are not: the exception has to be able
            // to say "not at all", which a pair of times cannot express.
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();

            // No timestamps: exceptions are child rows, replaced as a whole set every
            // time the daypart is saved, so their own created/updated dates say nothing.

            // One exception per weekday — a second row for the same day would make the
            // window ambiguous.
            $table->unique(['daypart_id', 'weekday']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daypart_exceptions');
    }
};
