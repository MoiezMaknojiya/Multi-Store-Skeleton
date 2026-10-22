<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The Ad Builder's categories (owner, 2026-10-22 — "category ya tag ka tab ho aur us mein mai tags ya category banao jese
 * menu, education, beer"): one list, made and changed by the platform alone, that every ad — the platform's and every
 * organization's — may be put in, several at once (ad_category_builder_ad). The name is unique as typed here and, folded
 * (case, accents, spacing), in AdCategoryController.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ad_categories')) {
            return;
        }

        Schema::create('ad_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 40)->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_categories');
    }
};
