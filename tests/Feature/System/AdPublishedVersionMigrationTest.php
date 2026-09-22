<?php

use App\Models\BuilderAd;
use App\Models\Media;
use App\Models\Store;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Every ad keeps the version on its screens
|--------------------------------------------------------------------------
|
| docs/AD-BUILDER-SPEC.md §9. The owner's database holds ads published before a published version was kept.
| One published and unchanged since is on its screens exactly as stored, so that is its version; one changed
| since has none to keep — it stays on its screens, marked changed, and its next Publish keeps one.
|
*/

test('an ad published and unchanged keeps what it stores as its version; one changed since keeps none', function () {
    $migration = require database_path('migrations/2026_09_21_120000_keep_the_published_version_of_each_ad.php');
    $migration->down();

    expect(Schema::hasColumn('builder_ads', 'published_document'))->toBeFalse();

    $store = Store::factory()->create();
    $upToDate = BuilderAd::factory()->withText('On air')->create(['store_id' => $store->id, 'name' => 'On air']);
    $changed = BuilderAd::factory()->withText('Edited since')->create(['store_id' => $store->id, 'name' => 'Edited since']);
    $never = BuilderAd::factory()->withText('Never published')->create(['store_id' => $store->id]);

    DB::table('builder_ads')->where('id', $upToDate->id)->update([
        'media_id' => Media::factory()->adPage()->create(['store_id' => $store->id])->id,
        'published_at' => '2026-09-19 08:00:00', 'updated_at' => '2026-09-19 08:00:00',
    ]);
    DB::table('builder_ads')->where('id', $changed->id)->update([
        'media_id' => Media::factory()->adPage()->create(['store_id' => $store->id])->id,
        'published_at' => '2026-09-19 08:00:00', 'updated_at' => '2026-09-20 01:57:28',
    ]);

    $migration->up();

    $rows = DB::table('builder_ads')->get()->keyBy('id');
    expect(json_decode($rows[$upToDate->id]->published_document, true))->toBe(json_decode($rows[$upToDate->id]->document, true))
        ->and($rows[$upToDate->id]->published_name)->toBe('On air')
        ->and($rows[$changed->id]->published_document)->toBeNull()
        ->and($rows[$never->id]->published_document)->toBeNull();

    expect(BuilderAd::find($upToDate->id)->status())->toBe('published')
        ->and(BuilderAd::find($changed->id)->status())->toBe('changed')
        ->and(BuilderAd::find($never->id)->status())->toBe('draft');
});
