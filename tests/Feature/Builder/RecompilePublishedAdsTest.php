<?php

use App\Models\ActivityLog;
use App\Models\BuilderAd;
use App\Models\Store;
use App\Services\AdPublisher;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| builder:recompile — the published pages written again, as the compiler writes them today
|--------------------------------------------------------------------------
|
| docs/AD-BUILDER-SPEC.md §9, §15. A page published before the compiler learnt something (its security
| policy, a fix) keeps what it was compiled with until it is written again. The command writes each one
| from the version on the screens — never the draft — and stamps its row so the screens fetch it anew.
|
*/

beforeEach(function () {
    Storage::fake('public');

    $this->store = Store::factory()->create();
});

/** A published ad whose page was written by an older compiler (no policy), with a newer draft on top. */
function publishedWithDraft(Store $store, string $published, string $draft): BuilderAd
{
    $ad = BuilderAd::factory()->withText($published)->create(['store_id' => $store->id, 'name' => 'Winter sale']);
    $media = app(AdPublisher::class)->publish($ad);

    // What an older compiler left: the page with no policy in it.
    Storage::disk('public')->put($media->path, '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'.$published.'</body></html>');

    // A change saved after, and not published.
    $document = $ad->document;
    $document['elements'][0]['text'] = $draft;
    $ad->update(['document' => $document, 'name' => 'Winter sale (new)']);

    return $ad->fresh('media');
}

test('every published page is written again from the version on the screens — never the draft', function () {
    $ad = publishedWithDraft($this->store, 'Coats 40% off', 'Coats 60% off');
    $key = $ad->media->cacheKey();

    $this->travel(5)->seconds();
    $this->artisan('builder:recompile')->expectsOutputToContain('1 page(s) written.')->assertSuccessful();

    $page = Storage::disk('public')->get($ad->media->path);
    $ad->refresh();

    expect($page)->toContain('http-equiv="Content-Security-Policy"')
        ->toContain('Coats 40% off')
        ->not->toContain('Coats 60% off')
        ->toContain('<title>Winter sale</title>')
        // The draft is untouched and still a draft: nothing was published by this.
        ->and($ad->document['elements'][0]['text'])->toBe('Coats 60% off')
        ->and($ad->hasUnpublishedChanges())->toBeTrue()
        // The screens are told: the row is stamped, so the cache key moved.
        ->and($ad->media->fresh()->cacheKey())->not->toBe($key)
        ->and($ad->media->fresh()->size)->toBe(strlen($page))
        ->and(ActivityLog::where('action', 'ad.recompiled')->count())->toBe(1);
});

test('an ad with no kept version is named and left alone; an unpublished one is not touched', function () {
    $legacy = BuilderAd::factory()->withText('Old one')->published()->create(['store_id' => $this->store->id, 'name' => 'Legacy']);
    $legacy->forceFill(['published_document' => null])->save();
    Storage::disk('public')->put($legacy->media->path, 'as it was');

    $draft = BuilderAd::factory()->withText('Never out')->published()->create(['store_id' => $this->store->id, 'name' => 'Pulled']);
    $draft->forceFill(['published_at' => null])->save();
    Storage::disk('public')->put($draft->media->path, 'pulled page');

    $this->artisan('builder:recompile')
        ->expectsOutputToContain('Skipped #'.$legacy->id.' Legacy')
        ->expectsOutputToContain('0 page(s) written.')
        ->assertSuccessful();

    expect(Storage::disk('public')->get($legacy->media->path))->toBe('as it was')
        ->and(Storage::disk('public')->get($draft->media->path))->toBe('pulled page');
});

test('--ad names the ads to write; the rest stay as they are', function () {
    $first = publishedWithDraft($this->store, 'First', 'First draft');
    $second = publishedWithDraft($this->store, 'Second', 'Second draft');

    $this->artisan('builder:recompile', ['--ad' => [$second->id]])->assertSuccessful();

    expect(Storage::disk('public')->get($first->media->path))->not->toContain('Content-Security-Policy')
        ->and(Storage::disk('public')->get($second->media->path))->toContain('Content-Security-Policy');
});
