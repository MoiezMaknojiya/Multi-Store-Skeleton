<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * The Ad Builder's shelf gets a part the platform shares with every shop (owner, 2026-09-29: "sub store k liya
 * upload karna ho toh takay woo mere asset ko use kar sake"): an asset with no shop — `store_id` NULL — is the
 * platform's, and every shop's designs may use it. A shop's own asset still names its shop, and its foreign key still
 * cascades, so a deleted shop takes its own files and never the shared ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('builder_assets', function (Blueprint $table) {
            $table->foreignId('store_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // A shared asset has no shop to name once the column must name one, so going back takes the shared ones: their
        // files first, by the paths their rows name (never a folder), then the rows. Only a throwaway database is ever
        // rolled back (a browser test after each run); the owner's never is.
        DB::table('builder_assets')->whereNull('store_id')->orderBy('id')->each(function (object $asset) {
            Storage::disk($asset->disk)->delete(array_values(array_filter([$asset->path, $asset->thumbnail_path])));
        });

        DB::table('builder_assets')->whereNull('store_id')->delete();

        Schema::table('builder_assets', function (Blueprint $table) {
            $table->foreignId('store_id')->nullable(false)->change();
        });
    }
};
