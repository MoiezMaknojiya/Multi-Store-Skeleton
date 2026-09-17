<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which screens a campaign runs on.
 *
 * Targeting is per SCREEN rather than per store, because a shop can carry ads on
 * its window television and refuse them on the one over the children's tables —
 * and because a brand buys "these 50 screens", not "these 30 shops". The panel
 * offers "select every screen in this store" so choosing a whole shop is one click.
 *
 * Separate from the consent flags on stores and screens, and both are required:
 * consent is a standing fact about the shop, targeting is a decision about one
 * campaign. A screen carries an ad only when it agreed to carry ads AND this
 * campaign chose it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_screen', function (Blueprint $table) {
            $table->id();

            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('screen_id')->constrained()->cascadeOnDelete();

            // No timestamps: this is a plain join row, replaced as a whole set every
            // time a campaign's targets are saved.

            $table->unique(['campaign_id', 'screen_id']);
            // Every device manifest asks it from the screen's side.
            $table->index('screen_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_screen');
    }
};
