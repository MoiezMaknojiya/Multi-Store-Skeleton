<?php

namespace Tests\Browser;

use App\Models\Media;
use App\Models\PlaylistItem;
use App\Models\Screen;
use App\Models\Store;
use App\Models\User;
use App\Services\MediaStorage;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class PlaylistFlowTest extends DuskTestCase
{
    use DatabaseMigrations;

    private function makeOwner(Store $store): User
    {
        $this->seedSuperAdmin();

        return $this->storeMember($store, [
            'screen-view', 'screen-store', 'screen-update', 'screen-destroy', 'screen-playlist',
            'media-view', 'media-store', 'media-update', 'media-destroy',
        ]);
    }

    /**
     * Remove a test's uploads and prove they are gone.
     *
     * The database rolls back after each test; the disk does not, so anything a
     * test puts on the Dusk disk (storage/app/dusk-public, served at /dusk-storage)
     * it has to take out again.
     *
     * The order matters, and it cost real megabytes to learn: the TV has to be
     * OFF the player page first. A <video> streams its file and holds it open,
     * and on Windows an open file cannot be unlinked — Storage::delete() then
     * fails silently and the clip survives every run. An <img> loads and lets go,
     * which is why only videos ever leaked. Leaving the player also stops its poll
     * and heartbeat timers, which live as long as the page and would otherwise
     * follow this browser into the next test.
     */
    private function removeUploads(Browser $tv, Media ...$media): void
    {
        $tv->visit('/login');

        // Drop the device identity too. This browser is handed to the next test,
        // and a leftover token makes the player start playback, take a 401 and
        // wipe storage — racing whatever that test is trying to plant.
        $tv->script('localStorage.clear();');

        foreach ($media as $item) {
            app(MediaStorage::class)->delete($item);

            foreach (array_filter([$item->path, $item->thumbnail_path]) as $path) {
                $this->assertFalse(
                    Storage::disk('public')->exists($path),
                    "left {$path} on disk — a Dusk run must not litter storage/app/dusk-public"
                );
            }
        }
    }

    /**
     * The whole product in one test: a TV pairs itself, the owner uploads a file
     * and drops it on the playlist, and the picture appears on the TV — with no
     * page reload anywhere and nobody but the shop owner involved.
     */
    public function test_content_reaches_the_tv_end_to_end(): void
    {
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->makeOwner($store);
        $poster = $this->fixtureImage('flow-poster.png', 210, 60, 90);

        $this->browse(function (Browser $tv, Browser $panel) use ($owner, $store, $poster) {
            // -- 1. The TV asks to be adopted -----------------------------------
            $tv->visit('/player');
            $tv->waitFor('@pairing-code', 15);
            $tv->waitUntil('document.querySelector(\'[dusk="pairing-code"]\').textContent.trim().length === 6', 15);
            $code = trim($tv->text('@pairing-code'));

            // -- 2. The owner pairs it from their own dashboard ------------------
            $this->freshSession($panel);
            $panel->loginAs($owner);
            $this->switchToStore($panel, $store);

            $panel->visit('/screens');
            $this->waitForAlpine($panel);
            $this->clickAndAwait($panel, '@add-screen', fn (Browser $b) => $b->waitFor('@screen-pair-form', 3));
            $this->jsType($panel, '@screen-code', $code);
            $this->jsType($panel, '@screen-name', 'Counter TV');
            $this->jsClick($panel, '@screen-pair-save');
            $panel->waitForText('Counter TV', 10);

            $screen = Screen::where('name', 'Counter TV')->firstOrFail();

            // -- 3. The TV starts, with nothing to show yet ----------------------
            // Up to a full POLL_MS (30s) before the TV notices it was claimed.
            $tv->waitForText('No content', 60);

            // -- 4. The owner uploads a file ------------------------------------
            $panel->visit('/media');
            $this->waitForAlpine($panel);
            $this->clickAndAwait($panel, '@upload-media', fn (Browser $b) => $b->waitFor('@media-upload-form', 3));
            $panel->attach('@media-file', $poster);
            $this->jsType($panel, '@media-title', 'Opening Poster');
            $this->jsClick($panel, '@media-upload-save');
            $panel->waitForText('Opening Poster', 15);

            $media = Media::where('title', 'Opening Poster')->firstOrFail();

            // -- 5. …and puts it on the screen's playlist -----------------------
            $panel->visit("/screens/{$screen->id}");
            $this->waitForAlpine($panel);
            $panel->waitForText('Opening Poster');
            $panel->assertSee('Nothing here yet');

            $this->jsClick($panel, '@playlist-add-'.$media->id);
            $panel->waitForText('1 item');
            $this->jsClick($panel, '@playlist-save');
            $panel->waitUsing(10, 200, fn () => PlaylistItem::where('screen_id', $screen->id)->count() === 1);

            $item = PlaylistItem::where('screen_id', $screen->id)->firstOrFail();
            $this->assertSame($media->id, $item->media_id);
            $this->assertSame(10, $item->duration_seconds);   // the image default

            // -- 6. The picture actually appears on the TV ----------------------
            $tv->waitUntil('!!document.querySelector("#layer-a img, #layer-b img")', 45);
            $src = $tv->script('return document.querySelector("#layer-a img, #layer-b img").getAttribute("src");')[0];
            $this->assertStringContainsString(config('filesystems.disks.public.url').'/media/', $src);
            $tv->waitUntilMissingText('No content');

            // -- 7. Clearing the playlist puts the TV back to "No content" ------
            $this->jsClick($panel, '@playlist-remove-0');
            $panel->waitForText('0 items');
            $this->jsClick($panel, '@playlist-save');
            $panel->waitUsing(10, 200, fn () => PlaylistItem::where('screen_id', $screen->id)->count() === 0);

            $tv->waitForText('No content', 45);

            $this->removeUploads($tv, $media);
        });
    }

    /**
     * Several files on one screen: the TV shows each in turn, for its own
     * duration, and then starts again at the top. This is the loop a shop
     * actually runs all day.
     */
    public function test_a_screen_cycles_through_several_images(): void
    {
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $screen = Screen::factory()->withToken('loop-token')->create([
            'store_id' => $store->id, 'name' => 'Counter TV',
        ]);

        // Real files on the Dusk disk (storage/app/dusk-public, served at /dusk-storage),
        // or the browser has nothing to render.
        $media = [];
        $palette = [
            ['loop-a.png', 220, 40, 40],
            ['loop-b.png', 40, 80, 220],
            ['loop-c.png', 40, 190, 90],
            ['loop-d.png', 240, 190, 40],
        ];

        foreach ($palette as $position => [$name, $r, $g, $b]) {
            $upload = new UploadedFile($this->fixtureImage($name, $r, $g, $b), $name, 'image/png', null, true);
            $row = Media::create([
                ...app(MediaStorage::class)->store($upload, $store->id, []),
                'title' => $name,
            ]);
            $media[] = $row;

            PlaylistItem::create([
                'screen_id' => $screen->id,
                'media_id' => $row->id,
                'position' => $position,
                // Short, so the test does not sit and wait.
                'duration_seconds' => 2,
            ]);
        }

        $this->browse(function (Browser $tv) use ($media) {
            // Plant the token from a page that is NOT the player: loading the
            // player first would start its own boot, and a stale token from an
            // earlier test makes it answer 401 and wipe localStorage — racing
            // whatever is written here.
            $tv->visit('/login');
            $tv->script("localStorage.clear(); localStorage.setItem('signage.device.token', 'loop-token');");
            $tv->visit('/player');

            // The player keeps two stacked layers and swaps which one is shown, so
            // both can hold an image at once — read the VISIBLE one, not the first
            // in the document.
            $visible = '#layer-a:not([hidden]) img, #layer-b:not([hidden]) img';
            $srcOf = fn () => $tv->script("return (document.querySelector('{$visible}') || {}).src || '';")[0];

            $tv->waitUntil("!!document.querySelector('{$visible}')", 30);
            $this->assertStringContainsString(config('filesystems.disks.public.url').'/media/', $srcOf());

            // Every file in turn, in the order the playlist was saved — then round
            // again to the first. Nobody touches anything; the TV does it alone.
            $expected = array_map(fn (Media $item) => basename($item->path), $media);
            $this->assertStringContainsString($expected[0], $srcOf());

            // Read the wall as it changes, one name each time the picture does: the order
            // is what the TV really showed, recorded as it happened, never assumed.
            $seen = [];
            $tv->waitUsing(45, 100, function () use ($srcOf, &$seen) {
                $name = basename((string) parse_url($srcOf(), PHP_URL_PATH));

                if ($name !== '' && end($seen) !== $name) {
                    $seen[] = $name;
                }

                return count($seen) >= 5;
            });

            // a, b, c, d, then a again.
            $this->assertSame([...$expected, $expected[0]], array_slice($seen, 0, 5));

            $this->removeUploads($tv, ...$media);
        });
    }

    /**
     * The shop switches the TV off at night and on again in the morning.
     *
     * Nobody goes near the panel and nobody re-enters a code: the player has to
     * pick itself up from the token it kept, ask the server what to show, and
     * report itself alive again — all on its own. This is the single most common
     * thing that will ever happen to a screen in a shop, and it happens every day
     * of its life, so it is worth proving rather than assuming.
     */
    public function test_a_tv_switched_off_overnight_comes_back_on_its_own(): void
    {
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $screen = Screen::factory()->withToken('restart-token')->create([
            'store_id' => $store->id, 'name' => 'Counter TV',
        ]);

        $upload = new UploadedFile($this->fixtureImage('restart-poster.png', 200, 90, 40), 'restart-poster.png', 'image/png', null, true);
        $poster = Media::create([
            ...app(MediaStorage::class)->store($upload, $store->id, []),
            'title' => 'Morning Poster',
        ]);

        PlaylistItem::create([
            'screen_id' => $screen->id,
            'media_id' => $poster->id,
            'position' => 0,
            'duration_seconds' => 30,
        ]);

        $this->browse(function (Browser $tv) use ($screen, $poster) {
            $visible = '#layer-a:not([hidden]) img, #layer-b:not([hidden]) img';
            $srcOf = fn () => $tv->script("return (document.querySelector('{$visible}') || {}).src || '';")[0];

            /* ── Evening: the screen is up and playing ──────────────────── */
            $tv->visit('/login');
            $tv->script("localStorage.clear(); localStorage.setItem('signage.device.token', 'restart-token');");
            $tv->visit('/player');

            $tv->waitUsing(30, 200, fn () => str_contains($srcOf(), basename($poster->path)));

            /* ── Closing time: the set is switched off ──────────────────── */
            // Navigating right away from the player is what a power cut does to
            // it — the page and every timer it owns are gone. What survives is
            // localStorage, exactly as it survives on a real television.
            $tv->visit('/login');

            // Age the last heartbeat, so a fresh one is unmistakable rather than
            // something that might have been left over from before.
            $screen->forceFill(['last_seen_at' => now()->subHours(9)])->save();
            $this->assertFalse($screen->fresh()->is_online);

            /* ── Morning: someone presses the power button ──────────────── */
            // Nothing is planted here. No code is typed. The player gets only what
            // it kept for itself.
            $tv->visit('/player');

            // It does NOT ask to be paired again…
            $tv->waitUsing(45, 250, fn () => str_contains($srcOf(), basename($poster->path)));
            $tv->assertMissing('@pairing-code');

            // …and it has told the server it is back, so the owner's panel turns
            // green by itself too. This is the half that proves it really spoke to
            // the server rather than re-rendering something it had cached.
            $tv->waitUsing(20, 250, fn () => $screen->fresh()->last_seen_at?->gt(now()->subMinutes(2)) === true);
            $this->assertTrue($screen->fresh()->is_online, 'the screen came back but never reported in');

            $this->removeUploads($tv, $poster);
        });
    }

    /**
     * A mixed playlist where one item is a video the device cannot decode —
     * a corrupt upload, or a codec this box lacks. The screen must carry on
     * showing the rest instead of freezing on the bad file or spinning on it.
     */
    public function test_a_video_that_will_not_play_does_not_stop_the_screen(): void
    {
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $screen = Screen::factory()->withToken('mixed-token')->create([
            'store_id' => $store->id, 'name' => 'Counter TV',
        ]);

        $images = [];
        foreach ([['mixed-a.png', 200, 30, 60], ['mixed-b.png', 30, 120, 200]] as [$name, $r, $g, $b]) {
            $upload = new UploadedFile($this->fixtureImage($name, $r, $g, $b), $name, 'image/png', null, true);
            $images[] = Media::create([
                ...app(MediaStorage::class)->store($upload, $store->id, []),
                'title' => $name,
            ]);
        }

        // A file that says it is a video and is not: exactly what a bad upload or
        // an unsupported codec looks like to the browser.
        $brokenPath = "media/{$store->id}/broken-clip.mp4";
        Storage::disk('public')->put($brokenPath, 'this is not a video');
        $broken = Media::create([
            'store_id' => $store->id,
            'title' => 'Broken Clip',
            'type' => Media::TYPE_VIDEO,
            'mime_type' => 'video/mp4',
            'disk' => 'public',
            'path' => $brokenPath,
            'size' => 19,
            'orientation' => 'landscape',
            'duration_seconds' => 10,
        ]);

        // image, broken video, image. The video's line is a minute long on purpose: its
        // backstop is that minute and five seconds more, so the screen can only reach the
        // next image in the time allowed below by noticing the file will not play.
        foreach ([$images[0], $broken, $images[1]] as $position => $item) {
            PlaylistItem::create([
                'screen_id' => $screen->id,
                'media_id' => $item->id,
                'position' => $position,
                'duration_seconds' => $item->is($broken) ? 60 : 2,
            ]);
        }

        $this->browse(function (Browser $tv) use ($images, $broken) {
            $tv->visit('/login');
            $tv->script("localStorage.clear(); localStorage.setItem('signage.device.token', 'mixed-token');");
            $tv->visit('/player');

            // Everything that is put on screen, whatever it is — an <img> or a <video> —
            // recorded the moment its layer is shown. A look at one instant could only
            // ever see whatever happened to be up at that instant.
            $tv->script(<<<'JS'
                window.__shown = [];
                ['layer-a', 'layer-b'].forEach((id) => {
                    const layer = document.getElementById(id);
                    new MutationObserver(() => {
                        const node = layer.firstElementChild;
                        if (! layer.hidden && node) window.__shown.push(node.tagName + ' ' + (node.getAttribute('src') || ''));
                    }).observe(layer, { attributes: true, attributeFilter: ['hidden'] });
                });
            JS);

            $visible = '#layer-a:not([hidden]) img, #layer-b:not([hidden]) img';
            $srcOf = fn () => $tv->script("return (document.querySelector('{$visible}') || {}).src || '';")[0];

            // The first image comes up.
            $tv->waitUsing(30, 150, fn () => str_contains($srcOf(), basename($images[0]->path)));
            $this->assertStringContainsString(basename($images[0]->path), $srcOf());

            // The broken video is skipped, and the screen lands on the next image
            // rather than sitting on a black frame. Two seconds of the first image and a
            // moment's breathing room over the bad file (BROKEN_ITEM_PAUSE_MS) fit easily
            // in twelve; waiting out the video's own backstop would take over a minute.
            $tv->waitUsing(12, 150, fn () => str_contains($srcOf(), basename($images[1]->path)));
            $this->assertStringContainsString(basename($images[1]->path), $srcOf());

            // And the loop keeps turning: back round to the first.
            $tv->waitUsing(30, 150, fn () => str_contains($srcOf(), basename($images[0]->path)));
            $this->assertStringContainsString(basename($images[0]->path), $srcOf());

            // The bad file never became the thing on screen, at any moment.
            $shown = $tv->script('return window.__shown;')[0];
            $this->assertNotEmpty($shown, 'nothing was recorded going on screen');
            foreach ($shown as $onScreen) {
                $this->assertStringNotContainsString('broken-clip', $onScreen, 'the file that will not play was put on screen');
            }

            $this->removeUploads($tv, ...[...$images, $broken]);
        });
    }

    /**
     * A real, decodable video: it plays and the screen moves on when the video
     * ENDS, not when a timer says so.
     *
     * There is no encoder on the machine running these tests, so the video is
     * recorded by the browser itself: a canvas captured through MediaRecorder
     * produces a genuine MP4, which is then uploaded exactly like any other file.
     * MP4 rather than WebM because MP4 is what a shop owner actually uploads —
     * it comes off their phone — so that is the path worth proving. A Chrome
     * without an MP4 encoder skips the test instead of falling back to a format
     * nobody uses in practice.
     */
    public function test_a_real_video_plays_and_the_screen_moves_on_when_it_ends(): void
    {
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->makeOwner($store);
        $screen = Screen::factory()->withToken('video-token')->create([
            'store_id' => $store->id, 'name' => 'Counter TV',
        ]);

        $poster = new UploadedFile($this->fixtureImage('after-clip.png', 250, 120, 20), 'after-clip.png', 'image/png', null, true);
        $image = Media::create([
            ...app(MediaStorage::class)->store($poster, $store->id, []),
            'title' => 'After Clip',
        ]);

        $this->browse(function (Browser $panel, Browser $tv) use ($owner, $store, $screen, $image) {
            $this->freshSession($panel);
            $panel->loginAs($owner);
            $this->switchToStore($panel, $store);
            $panel->visit('/media');
            $this->waitForAlpine($panel);

            // Record roughly a second of canvas as H.264 MP4, then upload it the
            // same way the browser would upload any chosen file.
            $panel->script(<<<'JS'
                window.__uploadDone = false;
                (async () => {
                    const types = ['video/mp4;codecs=avc1.42E01E', 'video/mp4'];
                    const mimeType = types.find((t) => MediaRecorder.isTypeSupported(t));
                    if (!mimeType) {
                        window.__uploadDone = 'no-mp4-encoder';
                        return;
                    }

                    const canvas = document.createElement('canvas');
                    canvas.width = 320; canvas.height = 180;
                    const context = canvas.getContext('2d');
                    let frame = 0;
                    const draw = setInterval(() => {
                        context.fillStyle = frame++ % 2 ? '#0a4' : '#048';
                        context.fillRect(0, 0, 320, 180);
                    }, 100);

                    const chunks = [];
                    const recorder = new MediaRecorder(canvas.captureStream(25), { mimeType });
                    recorder.ondataavailable = (e) => chunks.push(e.data);
                    recorder.onstop = async () => {
                        clearInterval(draw);
                        const body = new FormData();
                        body.append('file', new File(chunks, 'clip.mp4', { type: 'video/mp4' }));
                        body.append('title', 'Recorded Clip');
                        body.append('duration_seconds', '1');
                        const response = await fetch('/media', {
                            method: 'POST',
                            body,
                            headers: {
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                        });
                        window.__uploadDone = response.ok ? 'ok' : 'failed ' + response.status;
                    };
                    recorder.start();
                    setTimeout(() => recorder.stop(), 1200);
                })();
            JS);

            $panel->waitUsing(30, 300, fn () => $panel->script('return window.__uploadDone;')[0] !== false);
            $outcome = $panel->script('return window.__uploadDone;')[0];

            if ($outcome === 'no-mp4-encoder') {
                $this->markTestSkipped('This Chrome build cannot record MP4, so there is no real video to play.');
            }

            $this->assertSame('ok', $outcome);

            $clip = Media::where('title', 'Recorded Clip')->firstOrFail();
            $this->assertSame(Media::TYPE_VIDEO, $clip->type);

            // Video first, image second.
            foreach ([$clip, $image] as $position => $item) {
                PlaylistItem::create([
                    'screen_id' => $screen->id,
                    'media_id' => $item->id,
                    'position' => $position,
                    // Long enough that a timer cannot be what advances the video:
                    // only the video actually ending can move the screen on in time.
                    'duration_seconds' => $position === 0 ? 60 : 3,
                ]);
            }

            $tv->visit('/login');
            $tv->script("localStorage.clear(); localStorage.setItem('signage.device.token', 'video-token');");
            $tv->visit('/player');

            // The video really is on screen, muted so it can autoplay at all.
            $tv->waitUsing(30, 200, fn () => $tv->script('return !!document.querySelector("#layer-a:not([hidden]) video, #layer-b:not([hidden]) video");')[0]);
            $this->assertTrue($tv->script('return document.querySelector("#layer-a:not([hidden]) video, #layer-b:not([hidden]) video").muted;')[0]);

            // Its own ending — well inside the 60s backstop — hands over to the image.
            $visibleImage = '#layer-a:not([hidden]) img, #layer-b:not([hidden]) img';
            $tv->waitUsing(25, 200, fn () => $tv->script("return !!document.querySelector('{$visibleImage}');")[0]);
            $src = $tv->script("return document.querySelector('{$visibleImage}').src;")[0];
            $this->assertStringContainsString(basename($image->path), $src);

            $this->removeUploads($tv, $clip, $image);
        });
    }

    /** Every control on the playlist builder. */
    public function test_every_button_on_the_playlist_page(): void
    {
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->makeOwner($store);
        $screen = Screen::factory()->create(['store_id' => $store->id, 'name' => 'Counter TV']);

        $one = Media::factory()->create(['store_id' => $store->id, 'title' => 'Poster One']);
        $two = Media::factory()->create(['store_id' => $store->id, 'title' => 'Poster Two']);
        $clip = Media::factory()->video()->create([
            'store_id' => $store->id, 'title' => 'Promo Clip', 'duration_seconds' => 25,
        ]);

        $this->browse(function (Browser $browser) use ($owner, $store, $screen, $one, $two, $clip) {
            $this->freshSession($browser);
            $browser->loginAs($owner);
            $this->switchToStore($browser, $store);

            // -- Reached by the row's Playlist button, the main way in ----------
            $browser->visit('/screens');
            $this->waitForAlpine($browser);
            $browser->waitForText('Counter TV');
            $browser->assertSee('No playlist yet');
            $this->jsClick($browser, '@playlist-screen-'.$screen->id);
            $this->waitForAlpine($browser);
            $browser->waitForText('Nothing here yet');
            // The picker loads on its own request, so wait for it rather than
            // asserting on whatever happens to be painted at this instant.
            $browser->waitForText('Poster One');

            // -- Save is disabled until something actually changed --------------
            $browser->assertAttribute('@playlist-save', 'disabled', 'true');

            // -- Add three items -------------------------------------------------
            $this->jsClick($browser, '@playlist-add-'.$one->id);
            $this->jsClick($browser, '@playlist-add-'.$two->id);
            $this->jsClick($browser, '@playlist-add-'.$clip->id);
            $browser->waitForText('3 items');
            // A video takes its own length; an image takes the default.
            $browser->assertSee('45 secs');   // 10 + 10 + 25

            // The list the page will save, read from the component itself: what is sent is
            // this array, so a move that only redrew the rows would still save the old order.
            $order = fn () => $browser->script(
                'return Alpine.$data(document.querySelector(\'[x-data^="screenPlaylist"]\')).items.map(i => i.media_id);'
            )[0];
            $this->assertSame([$one->id, $two->id, $clip->id], $order());

            // -- Reorder: the first goes down one, then the last comes up one ----
            // one, two, clip → two, one, clip → two, clip, one. Both buttons change the
            // list in their click handler, so it can be read straight after each.
            $this->jsClick($browser, '@playlist-down-0');
            $this->assertSame([$two->id, $one->id, $clip->id], $order());
            $this->jsClick($browser, '@playlist-up-2');
            $this->assertSame([$two->id, $clip->id, $one->id], $order());

            // -- Retime an image -------------------------------------------------
            // Poster Two is first now, so this is its line.
            $this->jsType($browser, '@playlist-duration-0', '20');

            // -- Save -------------------------------------------------------------
            $this->jsClick($browser, '@playlist-save');
            $browser->waitUsing(10, 200, fn () => PlaylistItem::where('screen_id', $screen->id)->count() === 3);

            $saved = PlaylistItem::where('screen_id', $screen->id)->orderBy('position')->get();
            $this->assertSame([0, 1, 2], $saved->pluck('position')->all());
            $this->assertSame([$two->id, $clip->id, $one->id], $saved->pluck('media_id')->all());
            // The first line kept the new duration through the move.
            $this->assertSame(20, $saved->first()->duration_seconds);

            // -- The order survives a reload -------------------------------------
            $browser->refresh();
            $this->waitForAlpine($browser);
            $browser->waitForText('3 items');
            $this->assertSame([$two->id, $clip->id, $one->id], $order());

            // -- Search the picker ------------------------------------------------
            $this->jsType($browser, '@media-picker-search', 'Promo');
            // Scoped to the picker: the playlist on the left still holds Poster One,
            // so only the picker's own list is expected to narrow.
            $browser->within('@media-picker', function (Browser $picker) {
                $picker->waitUntilMissingText('Poster One');
                $picker->waitForText('Promo Clip');
            });
            $this->jsType($browser, '@media-picker-search', '');
            $browser->within('@media-picker', fn (Browser $picker) => $picker->waitForText('Poster One'));

            // -- Remove everything and save ---------------------------------------
            $this->jsClick($browser, '@playlist-remove-0');
            $this->jsClick($browser, '@playlist-remove-0');
            $this->jsClick($browser, '@playlist-remove-0');
            $browser->waitForText('Nothing here yet');
            $this->jsClick($browser, '@playlist-save');
            $browser->waitUsing(10, 200, fn () => PlaylistItem::where('screen_id', $screen->id)->count() === 0);

            // -- Back link returns to the list, which now reports the count ------
            $this->jsClick($browser, '@back-to-screens');
            $this->waitForAlpine($browser);
            $browser->waitForText('All Screens');
            $browser->waitForText('No playlist yet');
        });
    }

    /** A screen can never be pointed at another store's library. */
    public function test_the_picker_only_offers_this_store_s_files(): void
    {
        $alpha = Store::factory()->create(['name' => 'Alpha Mart']);
        $beta = Store::factory()->create(['name' => 'Beta Store']);
        $owner = $this->makeOwner($alpha);
        $screen = Screen::factory()->create(['store_id' => $alpha->id, 'name' => 'Counter TV']);

        Media::factory()->create(['store_id' => $alpha->id, 'title' => 'Alpha Poster']);
        Media::factory()->create(['store_id' => $beta->id, 'title' => 'Beta Poster']);

        $this->browse(function (Browser $browser) use ($owner, $alpha, $screen) {
            $this->freshSession($browser);
            $browser->loginAs($owner);
            $this->switchToStore($browser, $alpha);

            $browser->visit("/screens/{$screen->id}");
            $this->waitForAlpine($browser);
            $browser->waitForText('Alpha Poster')
                ->assertDontSee('Beta Poster');
        });
    }
}
