<?php

use App\Models\BuilderAd;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| An ad's orientation, on a database made before the column existed
|--------------------------------------------------------------------------
|
| docs/AD-BUILDER-SPEC.md §12. Every ad made before 2026-09-23 was 1920 × 1080 (the request refused any
| other size), so the column's default names them all correctly: nothing is backfilled, and the migration
| goes back down cleanly.
|
*/

test('every ad made before the column existed is landscape, and the migration is reversible', function () {
    $migration = require database_path('migrations/2026_09_23_100000_let_an_ad_be_portrait.php');
    $migration->down();

    expect(Schema::hasColumn('builder_ads', 'orientation'))->toBeFalse();

    $store = Store::factory()->create();

    // A row written by the app as it was: no orientation at all.
    $id = DB::table('builder_ads')->insertGetId([
        'store_id' => $store->id,
        'name' => 'Made before',
        'document' => json_encode(BuilderAd::blankDocument()),
        'created_at' => '2026-09-20 08:00:00',
        'updated_at' => '2026-09-20 08:00:00',
    ]);

    $migration->up();

    expect(Schema::hasColumn('builder_ads', 'orientation'))->toBeTrue();

    $ad = BuilderAd::find($id);

    expect($ad->orientation)->toBe('landscape')
        ->and($ad->isPortrait())->toBeFalse()
        ->and($ad->stageWidth())->toBe(1920)
        ->and($ad->stageHeight())->toBe(1080);
});
