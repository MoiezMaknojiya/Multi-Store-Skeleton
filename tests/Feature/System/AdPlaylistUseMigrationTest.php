<?php

use App\Models\BuilderAd;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| The playlist tick goes, and would come back unharmed (owner, 2026-10-01)
|--------------------------------------------------------------------------
|
| Publish alone decides where an ad may be chosen, so `builder_ads.in_playlists` — the "Show in playlists" tick of
| 2026-09-22 — is dropped. Going back must take nothing off a picker or a television: every ad published by then
| comes back ticked, and a draft unticked, as a new ad started.
|
*/

test('the tick column goes, and going back ticks every published ad and leaves a draft unticked', function () {
    expect(Schema::hasColumn('builder_ads', 'in_playlists'))->toBeFalse();

    $store = Store::factory()->create();
    $published = BuilderAd::factory()->withText()->published()->create(['store_id' => $store->id, 'name' => 'On air']);
    $draft = BuilderAd::factory()->withText()->create(['store_id' => $store->id, 'name' => 'Not published']);

    $migration = require database_path('migrations/2026_10_01_130000_take_the_playlist_tick_off_ads.php');
    $migration->down();

    expect(Schema::hasColumn('builder_ads', 'in_playlists'))->toBeTrue()
        ->and((bool) DB::table('builder_ads')->where('id', $published->id)->value('in_playlists'))->toBeTrue()
        ->and((bool) DB::table('builder_ads')->where('id', $draft->id)->value('in_playlists'))->toBeFalse();

    $migration->up();

    expect(Schema::hasColumn('builder_ads', 'in_playlists'))->toBeFalse()
        ->and(BuilderAd::find($published->id)->name)->toBe('On air');
});
