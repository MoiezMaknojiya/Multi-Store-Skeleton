<?php

use App\Models\ActivityLog;
use App\Models\BuilderAd;
use App\Models\BuilderAsset;
use App\Models\Media;
use App\Models\Store;
use App\Services\ExampleArtwork;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| builder:examples — four finished ads to learn the editor from
|--------------------------------------------------------------------------
|
| docs/AD-BUILDER-SPEC.md §10. The examples are ordinary documents, stored the way a save from the editor
| stores them, with their pictures on the store's own shelf — and they are safe to make again.
|
*/

beforeEach(function () {
    Storage::fake('public');
    Http::preventStrayRequests();

    $this->store = Store::factory()->create(['name' => 'Alpha Mart']);
});

test('it puts four finished, published ads and their pictures in the store', function () {
    $this->artisan('builder:examples', ['store' => $this->store->id, '--no-fonts' => true])->assertSuccessful();

    $ads = BuilderAd::where('store_id', $this->store->id)->get();

    expect($ads)->toHaveCount(4)
        ->and($ads->pluck('name')->all())->toEqualCanonicalizing([
            'Example · Winter Sale', 'Example · Fresh Coffee', 'Example · Grand Opening', 'Example · Burger Deal (Urdu)',
        ])
        ->and($ads->every(fn (BuilderAd $ad) => $ad->isPublished()))->toBeTrue()
        ->and(Media::where('type', Media::TYPE_HTML)->where('store_id', $this->store->id)->count())->toBe(4);

    // Every picture is on THIS store's shelf, in its folder, with a thumbnail — the way an upload is.
    $assets = BuilderAsset::all();

    expect($assets)->toHaveCount(count(ExampleArtwork::PIECES))
        ->and($assets->every(fn (BuilderAsset $asset) => $asset->store_id === $this->store->id))->toBeTrue()
        ->and($assets->every(fn (BuilderAsset $asset) => str_starts_with($asset->path, "builder/{$this->store->id}/assets/")))->toBeTrue()
        ->and($assets->every(fn (BuilderAsset $asset) => $asset->kind === BuilderAsset::KIND_IMAGE && $asset->width > 0))->toBeTrue();

    $assets->each(function (BuilderAsset $asset) {
        Storage::disk('public')->assertExists($asset->path);
        Storage::disk('public')->assertExists($asset->thumbnail_path);
    });

    // The owner's own example is in the coffee ad: fade in, then float up and down 10 px for ever.
    $coffee = $ads->firstWhere('name', 'Example · Fresh Coffee');
    $html = Storage::disk('public')->get($coffee->media->path);

    expect($html)->toContain('data-anim-id="fc_cup"')
        ->toContain('"fc_cup":{"in":{"effect":"fade"')
        ->toContain('"loop":{"effect":"float","axis":"y","amount":10')
        ->toContain('ad-runtime/anime.min.js')
        ->toContain('ad-runtime/runtime.js');

    // Urdu runs right to left without anybody having to say so.
    $burger = $ads->firstWhere('name', 'Example · Burger Deal (Urdu)');

    expect(Storage::disk('public')->get($burger->media->path))
        ->toContain('dir="auto"')
        ->toContain('زبردست ڈیل')
        ->toContain("font-family:'Noto Nastaliq Urdu', sans-serif;");

    // The command's work is in the store's activity log, by the system.
    expect(ActivityLog::where('store_id', $this->store->id)->where('action', 'ad.published')->count())->toBe(4)
        ->and(ActivityLog::where('action', 'ad.published')->first()->actor_name)->toBe('System');
});

test('running it again restores the examples instead of duplicating them', function () {
    $this->artisan('builder:examples', ['store' => $this->store->id, '--no-fonts' => true])->assertSuccessful();

    $winter = BuilderAd::firstWhere('name', 'Example · Winter Sale');
    $mediaId = $winter->media_id;
    $document = $winter->document;
    $document['elements'] = [];
    $winter->update(['document' => $document]);

    $this->artisan('builder:examples', ['store' => $this->store->id, '--no-fonts' => true])->assertSuccessful();

    expect(BuilderAd::count())->toBe(4)
        ->and(BuilderAsset::count())->toBe(count(ExampleArtwork::PIECES))
        ->and(Media::count())->toBe(4)
        ->and($winter->fresh()->document['elements'])->not->toBeEmpty()
        ->and($winter->fresh()->media_id)->toBe($mediaId);        // republished in place: playlists keep it
});

test('drafts, when asked', function () {
    $this->artisan('builder:examples', ['store' => $this->store->id, '--no-fonts' => true, '--no-publish' => true])->assertSuccessful();

    expect(BuilderAd::count())->toBe(4)
        ->and(BuilderAd::whereNotNull('media_id')->count())->toBe(0)
        ->and(Media::count())->toBe(0);
});

test('a store that does not exist is refused, and nothing is made', function () {
    $this->artisan('builder:examples', ['store' => 999, '--no-fonts' => true])->assertFailed();

    expect(BuilderAd::count())->toBe(0)->and(BuilderAsset::count())->toBe(0);
});

test('every example is a document the editor holds as it is — saved back unchanged', function () {
    $this->artisan('builder:examples', ['store' => $this->store->id, '--no-fonts' => true, '--no-publish' => true])->assertSuccessful();

    $designer = createStoreUser($this->store, ['ad-view', 'ad-update'], 'Designer');
    $this->actingAs($designer)->withSession(['current_store_id' => $this->store->id]);

    BuilderAd::all()->each(function (BuilderAd $ad) {
        $this->putJson("/builder/{$ad->id}", ['name' => $ad->name, 'document' => $ad->document])->assertOk();

        expect($ad->fresh()->document)->toEqual($ad->document);
    });
});

test('the artwork is the same every time it is drawn', function () {
    $artwork = new ExampleArtwork;

    foreach (array_keys(ExampleArtwork::PIECES) as $piece) {
        expect(md5($artwork->png($piece)))->toBe(md5($artwork->png($piece)));
    }
});
