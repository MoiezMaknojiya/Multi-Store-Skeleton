<?php

namespace Tests\Browser;

use App\Models\BuilderAd;
use App\Models\BuilderAsset;
use App\Models\Media;
use App\Models\PlaylistItem;
use App\Models\Role;
use App\Models\Screen;
use App\Models\Store;
use App\Services\MediaStorage;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The owner's case, 2026-09-28: "agar mein koi ad banata hu ad builder se aur woo 8 seconds ki ho aur background
 * 20 seconds toh hamari ads 8 seconds k bad change honi chahiye". The design says eight seconds; its background is
 * a real twenty-second video, made in the page and put on the shelf; the television plays the ad, and the next
 * item comes on eight seconds later — the video cut, as Xibo cuts a layout's and Canva a page's.
 */
class AdLengthOnScreenTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_an_eight_second_ad_over_a_twenty_second_background_video_moves_on_at_eight_seconds(): void
    {
        $this->seedSuperAdmin();
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->storeMember($store, Role::OWNER, 'owner@example.com');
        Screen::factory()->withToken('length-token')->create(['store_id' => $store->id, 'name' => 'Counter TV']);

        $picture = Media::create([
            ...app(MediaStorage::class)->store(new UploadedFile($this->fixtureImage('after-the-ad.png', 30, 160, 60), 'after-the-ad.png', 'image/png', null, true), $store->id, []),
            'title' => 'After the ad',
        ]);

        $this->browse(function (Browser $panel, Browser $tv) use ($owner, $store, $picture) {
            $this->freshSession($panel);
            $panel->loginAs($owner);
            $this->switchToStore($panel, $store);
            $panel->visit('/builder/assets');
            $this->waitForAlpine($panel);

            /* ── A real twenty-second video on the shelf ─────────────────── */
            $this->defineMakeVideo($panel);
            $panel->script(<<<'JS'
                window.__uploaded = false;
                (async () => {
                    const body = new FormData();
                    body.append('file', await window.__makeVideo(20, 'background.webm'));
                    body.append('duration_seconds', '20');
                    const response = await fetch('/builder/assets', {
                        method: 'POST', body,
                        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                    });
                    window.__uploaded = response.status;
                })();
            JS);
            $panel->waitUsing(60, 250, fn () => $panel->script('return window.__uploaded;')[0] !== false);
            $this->assertSame(200, $panel->script('return window.__uploaded;')[0]);

            $asset = BuilderAsset::sole();
            $this->assertSame(20, $asset->duration_seconds, 'the server measured the video itself');

            /* ── The design: that video as its background, eight seconds on screen ─ */
            $document = BuilderAd::blankDocument();
            $document['duration'] = 8;
            $document['stage']['background']['layers'] = [[
                'id' => 'bg_video', 'type' => 'video', 'assetId' => $asset->id, 'fit' => 'cover', 'visible' => true, 'opacity' => 1,
            ]];
            $ad = BuilderAd::create([
                'store_id' => $store->id, 'name' => 'Eight seconds', 'orientation' => BuilderAd::LANDSCAPE,
                'document' => $document, 'in_playlists' => true, 'created_by' => $owner->id,
            ]);

            // The editor says the length in its Stage panel, and Publish takes it along.
            $panel->visit('/builder/'.$ad->id);
            $this->waitForAlpine($panel);
            $panel->waitFor('@ad-length')->assertInputValue('@ad-length', '8')->assertMissing('@ad-length-earlier');
            $this->jsClick($panel, '@ad-publish');
            $panel->waitUsing(25, 250, fn () => $ad->fresh()->media_id !== null);

            $page = $ad->fresh()->media;
            $this->assertSame(8, $page->duration_seconds);
            $this->assertStringContainsString('autoplay muted loop playsinline', (string) Storage::disk('public')->get($page->path), 'the background repeats while the ad is up');

            // On the playlist: the ad, then the picture.
            $screen = Screen::where('store_id', $store->id)->sole();
            PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $page->id, 'position' => 0, 'duration_seconds' => 8]);
            PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $picture->id, 'position' => 1, 'duration_seconds' => 4]);

            /* ── The television ─────────────────────────────────────────── */
            $tv->visit('/login');
            $tv->script("localStorage.clear(); localStorage.setItem('signage.device.token', 'length-token');");
            $tv->visit('/player');

            // What comes on the glass, and when — watched from here on, so every change is timed in full.
            $tv->script(<<<'JS'
                window.__timeline = [];
                (function watch() {
                    const front = document.querySelector('#layer-a:not([hidden]) > *, #layer-b:not([hidden]) > *');
                    if (front && front !== window.__lastFront) {
                        window.__lastFront = front;
                        window.__timeline.push([front.tagName, performance.now()]);
                    }
                    setTimeout(watch, 50);
                })();
            JS);

            // The picture, then the ad, then the picture again: the ad's time on the glass, measured whole.
            $adSeconds = null;
            $tv->waitUsing(60, 250, function () use ($tv, &$adSeconds) {
                $adSeconds = $tv->script(<<<'JS'
                    const line = window.__timeline;
                    for (let i = 1; i + 1 < line.length; i++) {
                        if (line[i - 1][0] === 'IMG' && line[i][0] === 'IFRAME' && line[i + 1][0] === 'IMG') {
                            return (line[i + 1][1] - line[i][1]) / 1000;
                        }
                    }
                    return null;
                JS)[0];

                return $adSeconds !== null;
            }, 'the ad never came on between two showings of the picture');

            $this->assertGreaterThan(6.5, $adSeconds, "the ad was up for {$adSeconds}s");
            $this->assertLessThan(10.5, $adSeconds, "the ad was up for {$adSeconds}s — not the video's twenty");
        });
    }

    /** Play and Preview show the ad as a screen does: for its length, then from the start again, until stopped. */
    public function test_play_and_preview_start_the_ad_again_at_its_length(): void
    {
        $this->seedSuperAdmin();
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->storeMember($store, Role::OWNER, 'owner@example.com');

        $ad = BuilderAd::factory()->withText('Two seconds')->create(['store_id' => $store->id, 'name' => 'Two seconds']);
        $ad->update(['document' => [...$ad->document, 'duration' => 2]]);

        $this->browse(function (Browser $browser) use ($owner, $store, $ad) {
            $this->freshSession($browser);
            $browser->loginAs($owner);
            $this->switchToStore($browser, $store);
            $browser->visit('/builder/'.$ad->id);
            $this->waitForAlpine($browser);
            $browser->waitFor('@ad-length')->assertInputValue('@ad-length', '2');

            // Play: the television's own runtime, started again every two seconds — each start noted here.
            $browser->script(<<<'JS'
                window.__starts = [];
                const run = window.AdRuntime.run;
                window.AdRuntime.run = function () { window.__starts.push(performance.now()); return run.apply(this, arguments); };
            JS);
            $this->jsClick($browser, '@ad-play');
            $browser->waitFor('@preview-overlay')->assertSeeIn('@stage-size-note', 'Playing for 2 seconds, then from the start');
            $browser->waitUsing(10, 100, fn () => count($browser->script('return window.__starts;')[0]) >= 3);

            $starts = $browser->script('return window.__starts;')[0];
            $this->assertEqualsWithDelta(2000, $starts[1] - $starts[0], 500, 'the second start, two seconds on');
            $this->assertEqualsWithDelta(2000, $starts[2] - $starts[1], 500, 'and the third');

            // Stop stops the starts too.
            $this->jsClick($browser, '@ad-play');
            $browser->waitUntilMissing('@preview-overlay', 5);
            $stopped = count($browser->script('return window.__starts;')[0]);
            $browser->pause(3000);
            $this->assertCount($stopped, $browser->script('return window.__starts;')[0], 'nothing starts after Stop');

            // Preview: the page in a tab of its own, loaded again at the ad's length.
            $before = $browser->driver->getWindowHandles();
            $this->jsClick($browser, '@ad-preview');
            $browser->waitUsing(10, 200, fn () => count($browser->driver->getWindowHandles()) > count($before));

            $original = $browser->driver->getWindowHandle();
            $browser->driver->switchTo()->window(array_values(array_diff($browser->driver->getWindowHandles(), $before))[0]);
            $browser->waitUntil('document.readyState === "complete" && !!document.getElementById("ad-stage")', 10);

            $loaded = $browser->script('return performance.timeOrigin;')[0];
            $browser->waitUsing(10, 250, function () use ($browser, $loaded) {
                try {
                    return $browser->script('return document.readyState === "complete" ? performance.timeOrigin : 0;')[0] > $loaded;
                } catch (\Throwable) {
                    return false; // caught between two loads
                }
            }, 'the preview never loaded again');

            $browser->driver->close();
            $browser->driver->switchTo()->window($original);
        });
    }

    /** An ad on the screens since before designs had a length says so under Length, until Publish gives it one. */
    public function test_an_ad_published_before_lengths_says_so_until_it_is_published_again(): void
    {
        $this->seedSuperAdmin();
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->storeMember($store, Role::OWNER, 'owner@example.com');

        // As AdPublisher left a page before 2026-09-28: no length on its row.
        $ad = BuilderAd::factory()->withText('Old sale')->published()->create(['store_id' => $store->id, 'name' => 'Old sale']);
        $ad->media->update(['duration_seconds' => null]);

        $this->browse(function (Browser $panel) use ($owner, $store, $ad) {
            $this->freshSession($panel);
            $panel->loginAs($owner);
            $this->switchToStore($panel, $store);

            $panel->visit('/builder/'.$ad->id);
            $this->waitForAlpine($panel);
            $panel->waitFor('@ad-length-earlier')
                ->assertSeeIn('@ad-length-earlier', 'Published before ads had a length')
                ->assertInputValue('@ad-length', (string) BuilderAd::DEFAULT_SECONDS);

            $this->jsClick($panel, '@ad-publish');
            $panel->waitUsing(25, 250, fn () => $ad->media->fresh()->duration_seconds !== null);
            $panel->waitUntilMissing('@ad-length-earlier', 10);

            $this->assertSame(BuilderAd::DEFAULT_SECONDS, $ad->media->fresh()->duration_seconds);
        });
    }
}
