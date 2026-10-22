<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Which categories an ad is in (owner, 2026-10-22: several at once). Deleting a category takes it off every ad, and deleting
 * an ad — or the organization it belongs to — takes its rows with it: the foreign keys cascade, so nothing is left pointing at
 * either.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ad_category_builder_ad')) {
            return;
        }

        Schema::create('ad_category_builder_ad', function (Blueprint $table) {
            $table->foreignId('ad_category_id')->constrained('ad_categories')->cascadeOnDelete();
            $table->foreignId('builder_ad_id')->constrained('builder_ads')->cascadeOnDelete();
            $table->primary(['ad_category_id', 'builder_ad_id']);
            $table->index('builder_ad_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_category_builder_ad');
    }
};
