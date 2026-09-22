<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_rules', function (Blueprint $table) {
            $table->id();

            // A rule belongs to one item on one screen, so the same file can run at
            // lunchtime in the window and in the evening at the counter.
            $table->foreignId('playlist_item_id')->constrained()->cascadeOnDelete();

            // ── WHAT TIME, on a day this rule covers ──────────────────────────────
            // NULL means the whole day.
            $table->foreignId('daypart_id')->nullable()->constrained()->nullOnDelete();

            // ── WHICH DAYS ────────────────────────────────────────────────────────
            // Kept deliberately apart from the time above: "every Friday" and
            // "11:00-15:00" are two independent facts, and folding them together is
            // what makes the competing product need a separate modal for each.
            $table->date('starts_on')->nullable();   // null = since always
            $table->date('ends_on')->nullable();     // null = until forever

            // null = the plain date range above, no repeat.
            // daily | weekly | monthly_day | monthly_weekday | yearly
            $table->string('recurrence_type', 20)->nullable();
            $table->unsignedTinyInteger('recurrence_interval')->default(1);   // every N of them
            $table->json('recurrence_weekdays')->nullable();                  // weekly: [1,5]
            $table->unsignedTinyInteger('recurrence_monthday')->nullable();   // monthly_day: 21
            // monthly_weekday: "the third Thursday" is ordinal 3 + weekday 4;
            // signed, because -1 is how "the last one" is written.
            $table->tinyInteger('recurrence_ordinal')->nullable();
            $table->unsignedTinyInteger('recurrence_weekday')->nullable();
            $table->date('recurrence_until')->nullable();                     // null = indefinitely

            // Only for showing the rules back in the order they were written.
            $table->unsignedInteger('position')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_rules');
    }
};
