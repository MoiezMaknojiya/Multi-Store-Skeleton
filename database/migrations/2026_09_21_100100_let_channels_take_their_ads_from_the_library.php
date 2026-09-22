<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A channel stops keeping files of its own (docs/CHANNEL-CONTENT-SPEC.md, owner 2026-09-19): every ad is a row
 * of a media library, held by `channel_ads.media_id`. The file columns move to that row.
 *
 * The ads that exist already are carried over without touching a single file: each gets a media row that
 * names the file where it already lies (`channels/{id}/…`) — the channel's shop's library, or the platform's
 * for a platform channel. A folder name is only a folder name; the row is what says whose file it is.
 */
return new class extends Migration
{
    /** The columns a channel ad kept about its file, which a media row keeps from now on. */
    private const FILE_COLUMNS = [
        'type', 'mime_type', 'disk', 'path', 'thumbnail_path', 'size', 'width', 'height', 'orientation',
        'media_duration_seconds',
    ];

    public function up(): void
    {
        Schema::table('channel_ads', function (Blueprint $table) {
            $table->foreignId('media_id')->nullable()->after('channel_id')->constrained('media')->cascadeOnDelete();
        });

        DB::table('channel_ads')->lazyById()->each(function (object $ad) {
            $mediaId = DB::table('media')->insertGetId([
                'store_id' => DB::table('channels')->where('id', $ad->channel_id)->value('store_id'),
                'title' => $ad->title,
                'type' => $ad->type,
                'mime_type' => $ad->mime_type,
                'disk' => $ad->disk,
                'path' => $ad->path,
                'thumbnail_path' => $ad->thumbnail_path,
                'size' => $ad->size,
                'width' => $ad->width,
                'height' => $ad->height,
                'duration_seconds' => $ad->media_duration_seconds,
                'orientation' => $ad->orientation,
                'created_by' => $ad->created_by,
                'created_at' => $ad->created_at ?? now(),
                'updated_at' => $ad->updated_at ?? now(),
            ]);

            DB::table('channel_ads')->where('id', $ad->id)->update(['media_id' => $mediaId]);
        });

        Schema::table('channel_ads', function (Blueprint $table) {
            $table->dropColumn(self::FILE_COLUMNS);
        });

        Schema::table('channel_ads', function (Blueprint $table) {
            $table->foreignId('media_id')->nullable(false)->change();
        });
    }

    /**
     * Each ad takes its file's details back from its media row, and the rows this migration made (the ones
     * naming a file on a channel's old shelf) go again. A file uploaded through a channel AFTER the change
     * lives in a library folder and stays a library row: rolled back, that ad and that row share one file.
     */
    public function down(): void
    {
        Schema::table('channel_ads', function (Blueprint $table) {
            $table->string('type', 10)->nullable()->after('title');
            $table->string('mime_type', 150)->nullable()->after('type');
            $table->string('disk', 30)->default('public')->after('mime_type');
            $table->string('path')->nullable()->after('disk');
            $table->string('thumbnail_path')->nullable()->after('path');
            $table->unsignedBigInteger('size')->nullable()->after('thumbnail_path');
            $table->unsignedInteger('width')->nullable()->after('size');
            $table->unsignedInteger('height')->nullable()->after('width');
            $table->string('orientation', 10)->nullable()->after('height');
            $table->unsignedInteger('media_duration_seconds')->nullable()->after('orientation');
        });

        DB::table('channel_ads')->lazyById()->each(function (object $ad) {
            $media = DB::table('media')->where('id', $ad->media_id)->first();

            if ($media === null) {
                return;
            }

            DB::table('channel_ads')->where('id', $ad->id)->update([
                'type' => $media->type,
                'mime_type' => $media->mime_type,
                'disk' => $media->disk,
                'path' => $media->path,
                'thumbnail_path' => $media->thumbnail_path,
                'size' => $media->size,
                'width' => $media->width,
                'height' => $media->height,
                'orientation' => $media->orientation,
                'media_duration_seconds' => $media->duration_seconds,
            ]);
        });

        Schema::table('channel_ads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('media_id');
        });

        DB::table('media')->where('path', 'like', 'channels/%')->delete();
    }
};
