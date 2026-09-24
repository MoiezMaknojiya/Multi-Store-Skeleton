<?php

use App\Models\ActivityLog;
use App\Models\BuilderAd;
use App\Models\Store;
use App\Services\AdCompiler;
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

test('a page names the compiler that wrote it', function () {
    $ad = BuilderAd::factory()->withText('Hello')->create(['store_id' => $this->store->id]);
    $page = app(AdCompiler::class)->compile($ad);

    expect($page)->toContain('<meta name="ad-compiler" content="'.AdCompiler::VERSION.'">')
        ->and(AdCompiler::wroteCurrent($page))->toBeTrue()
        ->and(AdCompiler::wroteCurrent('<html><head><meta name="ad-compiler" content="2020-01-01"></head></html>'))->toBeFalse()
        ->and(AdCompiler::wroteCurrent(null))->toBeFalse();
});

test('--outdated writes only the pages an older compiler wrote, and leaves the rest as they are', function () {
    $old = publishedWithDraft($this->store, 'Old page', 'Old draft');
    $fresh = BuilderAd::factory()->withText('Fresh page')->create(['store_id' => $this->store->id, 'name' => 'Fresh']);
    app(AdPublisher::class)->publish($fresh);
    $fresh->refresh();

    $freshPage = Storage::disk('public')->get($fresh->media->path);
    [$oldKey, $freshKey] = [$old->media->cacheKey(), $fresh->media->cacheKey()];

    $this->travel(5)->seconds();
    $this->artisan('builder:recompile', ['--outdated' => true])
        ->expectsOutputToContain('1 page(s) written. 1 already current.')
        ->assertSuccessful();

    expect(Storage::disk('public')->get($old->media->path))->toContain('<meta name="ad-compiler" content="'.AdCompiler::VERSION.'">')
        ->and($old->media->fresh()->cacheKey())->not->toBe($oldKey)
        // The current one is not touched: no new copy for any screen to fetch.
        ->and(Storage::disk('public')->get($fresh->media->path))->toBe($freshPage)
        ->and($fresh->media->fresh()->cacheKey())->toBe($freshKey);

    // Every deploy runs it: the second time there is nothing left to write.
    $this->artisan('builder:recompile', ['--outdated' => true])
        ->expectsOutputToContain('0 page(s) written. 2 already current.')
        ->assertSuccessful();
});

test('--outdated writes a published page that is missing from the disk', function () {
    $ad = BuilderAd::factory()->withText('Lost page')->create(['store_id' => $this->store->id, 'name' => 'Lost']);
    $media = app(AdPublisher::class)->publish($ad);
    Storage::disk('public')->delete($media->path);

    $this->artisan('builder:recompile', ['--outdated' => true])->assertSuccessful();

    expect(Storage::disk('public')->get($media->path))->toContain('Lost page');
});

test('every deploy writes the out-of-date pages again — after the site has switched to the new release', function () {
    $release = (string) file_get_contents(base_path('deploy/server/release.sh'));
    $switch = strpos($release, 'mv -Tf "$APP/current.next" "$APP/current"');
    $recompile = strpos($release, 'php artisan builder:recompile --outdated');

    // Before the switch, a page would name a runtime version the old release does not serve — and a set that
    // fetched it then would keep the old file under the new address.
    expect($switch)->not->toBeFalse()
        ->and($recompile)->not->toBeFalse()
        ->and($recompile)->toBeGreaterThan($switch);
});

test('--ad names the ads to write; the rest stay as they are', function () {
    $first = publishedWithDraft($this->store, 'First', 'First draft');
    $second = publishedWithDraft($this->store, 'Second', 'Second draft');

    $this->artisan('builder:recompile', ['--ad' => [$second->id]])->assertSuccessful();

    expect(Storage::disk('public')->get($first->media->path))->not->toContain('Content-Security-Policy')
        ->and(Storage::disk('public')->get($second->media->path))->toContain('Content-Security-Policy');
});
