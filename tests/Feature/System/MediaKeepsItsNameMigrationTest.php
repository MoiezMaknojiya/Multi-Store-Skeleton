<?php

use App\Models\Media;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| A file keeps its name alone (owner, 2026-10-01)
|--------------------------------------------------------------------------
|
| "media library samaj lo asset ki terha hi ha ... phir playlist mein add kar k apne hisab se schedule set kar dega",
| "srif naam rakho": a file's own start, expiry and description go, columns and all, and when it plays is its playlist
| line's to say. The migration goes back down, with the columns empty again.
|
*/

test('the dates and the description leave the media table, and the migration goes back down', function () {
    expect(Schema::hasColumns('media', ['description', 'starts_at', 'expires_at']))->toBeFalse();

    $migration = require database_path('migrations/2026_10_01_100000_take_the_dates_and_description_off_media.php');
    $migration->down();

    expect(Schema::hasColumns('media', ['description', 'starts_at', 'expires_at']))->toBeTrue();

    // A file as it was before: with an expiry that had already passed and a description.
    $store = Store::factory()->create();
    $id = DB::table('media')->insertGetId([
        'store_id' => $store->id, 'title' => 'Old poster', 'description' => 'Shown until March', 'type' => 'image',
        'mime_type' => 'image/jpeg', 'disk' => 'public', 'path' => 'media/1/old.jpg', 'size' => 1000,
        'starts_at' => '2026-09-28 03:11:00', 'expires_at' => '2026-09-30 03:10:00',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $migration->up();

    expect(Schema::hasColumns('media', ['description', 'starts_at', 'expires_at']))->toBeFalse()
        // The file itself stays, and plays again wherever a line holds it: nothing of its own stops it now.
        ->and(Media::find($id)?->title)->toBe('Old poster')
        ->and(Media::find($id)->isPlayableNow())->toBeTrue();
});
