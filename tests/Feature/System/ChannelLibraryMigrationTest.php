<?php

use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Media;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Channels move their files into the media libraries
|--------------------------------------------------------------------------
|
| docs/CHANNEL-CONTENT-SPEC.md. The owner's database holds channel ads made the old way, each keeping a
| file of its own on the channel's shelf. Put back into that shape, filled the old way and run forward
| again, every ad has to come out holding a library row that names its file exactly where it lies — the
| organization's library for an organization's channel, the platform's for the platform's — and back again on a rollback.
|
*/

/** The migration that moves the files. */
function channelLibraryMigration(): object
{
    return require database_path('migrations/2026_09_21_100100_let_channels_take_their_ads_from_the_library.php');
}

/** The columns a channel ad kept about its file before the move. */
const OLD_FILE_COLUMNS = [
    'type', 'mime_type', 'disk', 'path', 'thumbnail_path', 'size', 'width', 'height', 'orientation', 'media_duration_seconds',
];

/** @param  array<string, mixed>  $row */
function oldChannelAd(array $row): int
{
    return DB::table('channel_ads')->insertGetId([
        'title' => 'An ad', 'type' => 'image', 'mime_type' => 'image/jpeg', 'disk' => 'public',
        'thumbnail_path' => null, 'size' => 120_000, 'width' => 1920, 'height' => 1080, 'orientation' => 'landscape',
        'media_duration_seconds' => null, 'duration_seconds' => 10, 'position' => 0, 'created_by' => null,
        'created_at' => now(), 'updated_at' => now(),
        ...$row,
    ]);
}

test("an ad kept the old way becomes a row of its channel's library, naming its file where it lies", function () {
    $organization = Organization::factory()->create();
    $organizationChannel = Channel::factory()->create(['organization_id' => $organization->id]);
    $platformChannel = Channel::factory()->create();
    $maker = User::factory()->create();
    $image = $video = null;

    withTheNamesOfTheirDay(function () use ($organizationChannel, $platformChannel, $maker, &$image, &$video) {
        $migration = channelLibraryMigration();
        $migration->down();

        expect(Schema::hasColumns('channel_ads', OLD_FILE_COLUMNS))->toBeTrue()
            ->and(Schema::hasColumn('channel_ads', 'media_id'))->toBeFalse();

        $image = oldChannelAd([
            'channel_id' => $organizationChannel->id, 'title' => 'Weekend deal',
            'path' => "channels/{$organizationChannel->id}/deal.jpg", 'thumbnail_path' => "channels/{$organizationChannel->id}/thumbs/deal.jpg",
            'duration_seconds' => 12, 'created_by' => $maker->id, 'created_at' => '2026-09-10 10:00:00',
        ]);
        $video = oldChannelAd([
            'channel_id' => $platformChannel->id, 'title' => 'GAMA promo', 'type' => 'video', 'mime_type' => 'video/mp4',
            'path' => "channels/{$platformChannel->id}/promo.mp4", 'size' => 9_000_000, 'width' => 1280, 'height' => 720,
            'media_duration_seconds' => 31, 'duration_seconds' => null, 'position' => 1,
        ]);

        $migration->up();
    });

    // The file columns are gone from the ad; it holds its library row instead, and has to.
    expect(collect(OLD_FILE_COLUMNS)->filter(fn (string $column) => Schema::hasColumn('channel_ads', $column))->all())->toBe([])
        ->and(Media::count())->toBe(2);

    $imageAd = ChannelAd::with('media')->findOrFail($image);
    expect($imageAd->media->organization_id)->toBe($organization->id)
        ->and($imageAd->media->title)->toBe('Weekend deal')
        ->and($imageAd->media->type)->toBe(Media::TYPE_IMAGE)
        ->and($imageAd->media->path)->toBe("channels/{$organizationChannel->id}/deal.jpg")
        ->and($imageAd->media->thumbnail_path)->toBe("channels/{$organizationChannel->id}/thumbs/deal.jpg")
        ->and($imageAd->media->size)->toBe(120_000)
        ->and($imageAd->media->created_by)->toBe($maker->id)
        ->and($imageAd->media->created_at->toDateTimeString())->toBe('2026-09-10 10:00:00')
        ->and($imageAd->play_seconds)->toBe(12);

    $videoAd = ChannelAd::with('media')->findOrFail($video);
    expect($videoAd->media->organization_id)->toBeNull()
        ->and($videoAd->type)->toBe(Media::TYPE_VIDEO)
        ->and($videoAd->media->duration_seconds)->toBe(31)
        ->and($videoAd->media->width)->toBe(1280)
        ->and($videoAd->duration_seconds)->toBeNull()
        ->and($videoAd->play_seconds)->toBe(31);

    expect(fn () => DB::table('channel_ads')->insert([
        'channel_id' => $organizationChannel->id, 'title' => 'No file', 'duration_seconds' => 10, 'position' => 2,
    ]))->toThrow(QueryException::class);
});

test('rolled back, each ad takes its file back and the rows made for it go — a library upload stays', function () {
    $channel = Channel::factory()->create();
    $moved = null;

    withTheNamesOfTheirDay(function () use ($channel, &$moved) {
        $migration = channelLibraryMigration();
        $migration->down();
        $moved = oldChannelAd(['channel_id' => $channel->id, 'title' => 'Moved', 'path' => "channels/{$channel->id}/moved.jpg"]);
        $migration->up();
    });

    // After the change, a file chosen from a library: that row belongs to the library, not to the move.
    $libraryFile = Media::factory()->platformOwned()->create(['path' => 'media/platform/chosen.jpg', 'width' => 1080, 'height' => 1920, 'orientation' => 'portrait']);
    $chosen = ChannelAd::factory()->create(['channel_id' => $channel->id, 'media_id' => $libraryFile->id, 'title' => 'Chosen']);

    withTheNamesOfTheirDay(function () use ($channel, $moved, $libraryFile, $chosen) {
        $migration = channelLibraryMigration();
        $migration->down();

        $rows = DB::table('channel_ads')->get()->keyBy('id');
        expect($rows[$moved]->path)->toBe("channels/{$channel->id}/moved.jpg")
            ->and($rows[$moved]->type)->toBe(Media::TYPE_IMAGE)
            ->and($rows[$chosen->id]->path)->toBe('media/platform/chosen.jpg')
            ->and($rows[$chosen->id]->orientation)->toBe('portrait')
            ->and(Schema::hasColumn('channel_ads', 'media_id'))->toBeFalse()
            ->and(DB::table('media')->where('path', 'like', 'channels/%')->exists())->toBeFalse()
            ->and(DB::table('media')->where('id', $libraryFile->id)->exists())->toBeTrue();

        // And forward once more, for the rest of the suite's sake: the schema is what the app expects.
        $migration->up();
    });

    expect(Schema::hasColumn('channel_ads', 'media_id'))->toBeTrue();
});

/** Whether a column of media takes a NULL. */
function mediaColumnIsNullable(string $column): bool
{
    return collect(Schema::getColumns('media'))->firstWhere('name', $column)['nullable'];
}

test("rolled back, the platform's library keeps its files — the column stays open to it while any is left", function () {
    // Neither deleting the platform's files nor refusing is acceptable: one loses a library, the other stops every
    // rollback queued behind this one (the browser tests roll the whole schema back after each test).
    $file = Media::factory()->platformOwned()->create();

    withTheNamesOfTheirDay(function () use ($file) {
        $migration = require database_path('migrations/2026_09_21_100000_let_the_platform_keep_a_media_library.php');
        $migration->down();

        expect(DB::table('media')->where('id', $file->id)->exists())->toBeTrue()
            ->and(mediaColumnIsNullable('store_id'))->toBeTrue();

        // With no file of the platform's left, an organization's again for every row — and forward once more, unharmed.
        DB::table('media')->where('id', $file->id)->delete();
        $migration->down();
        expect(mediaColumnIsNullable('store_id'))->toBeFalse();

        $migration->up();
        $migration->up();
    });

    expect(mediaColumnIsNullable('organization_id'))->toBeTrue();
});
