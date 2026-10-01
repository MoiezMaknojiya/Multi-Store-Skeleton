<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * An Ad Builder ad may be the platform's, made for every shop (owner, 2026-10-01: "mein all shop k liya ads kese banao?
 * jese asset mein ha woo ads sub ko dikhe aur woo copy kar sake"): an ad with no shop — `store_id` NULL — is shared, as
 * a shelf asset with none already is. Every shop sees it once it is published and copies it into its own Ads; its page
 * is published into the platform's library. A shop's own ad still names its shop, and its foreign key still cascades,
 * so a deleted shop takes its own ads and never the shared ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('builder_ads', function (Blueprint $table) {
            $table->foreignId('store_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // A shared ad has no shop to name once the column must name one, so going back takes the shared ones: the
        // library rows their pages are (which takes them out of any channel), then every file by the path its row
        // names (never a folder), then the ads. Only a throwaway database is ever rolled back; the owner's never is.
        DB::table('builder_ads')->whereNull('store_id')->orderBy('id')->each(function (object $ad) {
            $media = $ad->media_id === null ? null : DB::table('media')->where('id', $ad->media_id)->first();

            Storage::disk($media->disk ?? 'public')->delete(array_values(array_filter([$media?->path, $media?->thumbnail_path])));
            Storage::disk('public')->delete(array_values(array_filter([$ad->thumbnail_path])));

            if ($media !== null) {
                DB::table('media')->where('id', $media->id)->delete();
            }
        });

        DB::table('builder_ads')->whereNull('store_id')->delete();

        Schema::table('builder_ads', function (Blueprint $table) {
            $table->foreignId('store_id')->nullable(false)->change();
        });
    }
};
