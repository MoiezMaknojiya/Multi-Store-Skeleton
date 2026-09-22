<?php

use App\Models\BuilderAd;
use App\Models\Media;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Where an ad may play, on a database made before the tick existed
|--------------------------------------------------------------------------
|
| Owner, 2026-09-22: an ad is for channels only until "Show in playlists" is ticked. The rule must not take
| anything off a television that is already showing one, so every ad ALREADY published is ticked by the
| migration — it is what the pickers offer today — while a draft starts where a new ad starts: unticked.
|
*/

test('every ad already published may still be played from a playlist; a draft starts channels-only', function () {
    $migration = require database_path('migrations/2026_09_22_100000_let_an_ad_say_whether_playlists_may_use_it.php');
    $migration->down();

    expect(Schema::hasColumn('builder_ads', 'in_playlists'))->toBeFalse();

    $store = Store::factory()->create();
    $onScreens = BuilderAd::factory()->withText()->create(['store_id' => $store->id, 'name' => 'On air']);
    $draft = BuilderAd::factory()->withText()->create(['store_id' => $store->id, 'name' => 'Not published']);

    DB::table('builder_ads')->where('id', $onScreens->id)->update([
        'media_id' => Media::factory()->adPage()->create(['store_id' => $store->id])->id,
        'published_at' => '2026-09-20 08:00:00',
    ]);

    $migration->up();

    expect(BuilderAd::find($onScreens->id)->in_playlists)->toBeTrue()
        ->and(BuilderAd::find($draft->id)->in_playlists)->toBeFalse();
});
