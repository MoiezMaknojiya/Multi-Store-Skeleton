<?php

namespace Tests\Browser;

use App\Models\Media;
use App\Models\PlaylistItem;
use App\Models\Screen;
use App\Models\Store;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * A television with no line (docs/AD-BUILDER-SPEC.md §15): paired and playing, its worker holds the page,
 * the manifest and every file; then the server goes away — put into maintenance, so every request it
 * gets is a 503 and the worker's network-first paths fall back to their caches, exactly as they do when
 * there is no network at all — and the set plays on: what has not expired, then the holding picture once
 * everything has, through a reboot, until the line returns and the server's own answer takes over.
 *
 * Real Chrome, a real worker, real files: nothing here can be vouched for by a feature test.
 */
class OfflinePlayerTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_a_television_keeps_playing_from_its_cache_when_the_server_cannot_be_reached(): void
    {
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $screen = Screen::factory()->withToken('offline-token')->create(['store_id' => $store->id, 'name' => 'Counter TV']);

        $holding = $this->picture($store, 'holding.png', [30, 30, 30]);
        $screen->update(['default_media_id' => $holding->id]);

        // Two pictures with short lives: the first ends while the line is down, the second a while after.
        $soon = $this->picture($store, 'soon.png', [200, 40, 40], now()->addSeconds(50));
        $later = $this->picture($store, 'later.png', [40, 160, 60], now()->addSeconds(100));

        $soonLine = PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $soon->id, 'position' => 0, 'duration_seconds' => 3]);
        PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $later->id, 'position' => 1, 'duration_seconds' => 3]);

        $this->browse(function (Browser $tv) use ($soon, $later, $soonLine) {
            try {
                $tv->visit('/login');
                $tv->script("localStorage.clear(); localStorage.setItem('signage.device.token', 'offline-token');");
                $tv->visit('/player');

                /* ── 1. Live: a worker takes the page over and the whole playlist is warmed ── */
                $this->waitForPicture($tv, 'soon.png');
                $tv->waitUntil('document.body.dataset.source === "live"', 15, 'the first manifest was not marked live');
                $tv->waitUntil('!!navigator.serviceWorker.controller', 20, 'no worker took the page over');
                $tv->waitUntil('!!document.body.dataset.warmed', 30, 'the worker never said the cache was warmed');
                $this->assertSame('0', $tv->script('return document.body.dataset.warmMissing;')[0], 'every file the screen may need was fetched');

                $cached = $this->cachedMediaUrls($tv);

                foreach (['soon.png', 'later.png', 'holding.png'] as $file) {
                    $this->assertTrue(collect($cached)->contains(fn (string $url) => str_contains($url, $file)), "{$file} is in the worker's cache");
                }

                /* ── 2. The server goes away: the next poll is answered from memory, and the set plays on ── */
                $this->serverGoesAway();

                $tv->waitUntil('document.body.dataset.source === "cache"', 75, 'with the server down, no manifest came from the cache');
                $this->waitForPicture($tv, 'later.png', 45);
                $this->waitForPicture($tv, 'soon.png', 45);
                $this->assertGreaterThan(0, $tv->script('return document.querySelector("#layer-a:not([hidden]) img, #layer-b:not([hidden]) img").naturalWidth;')[0],
                    'the picture on the glass really loaded — from the cache, since the server answers nothing');

                /* ── 3. The first picture expires while the line is down: only the second plays on ── */
                $tv->waitUsing(60, 500, fn () => now()->gt($soon->expires_at));
                // The next poll, answered from memory, drops the expired picture — and the page says which LINE
                // (a manifest item is a playlist line, not a file: the same file twice is two lines).
                $tv->waitUsing(60, 500, fn () => $tv->script('return document.body.dataset.dropped;')[0] === (string) $soonLine->id,
                    'after the first picture expired, the cached manifest did not drop it');

                // Then the glass shows the second alone: two passes of a three-second picture with no sign of
                // the first. (Seeing the second once proves nothing — the two took turns before.)
                $lastSeenSoon = microtime(true);
                $tv->waitUsing(75, 250, function () use ($tv, &$lastSeenSoon) {
                    $showing = $this->onScreen($tv);

                    if (str_contains($showing, 'soon.png')) {
                        $lastSeenSoon = microtime(true);
                    }

                    return microtime(true) - $lastSeenSoon > 7 && str_contains($showing, 'later.png');
                }, 'after the first picture expired, it kept coming back');

                /* ── 4. …then the second: the holding picture takes the glass ── */
                $tv->waitUsing(60, 500, fn () => now()->gt($later->expires_at));
                $this->waitForPicture($tv, 'holding.png', 60);

                /* ── 5. Rebooted with no line, the set still has its page — and its holding picture ── */
                $tv->refresh();
                $this->waitForPicture($tv, 'holding.png', 45);
                $tv->waitUntil('document.body.dataset.source === "cache"', 30, 'rebooted with the server down, the page did not play from the cache');

                /* ── 6. The line returns: the server's own answer, and the set is live again ── */
                $this->serverComesBack();

                $tv->waitUntil('document.body.dataset.source === "live"', 75, 'with the server back, the set did not go live again');
                $this->waitForPicture($tv, 'holding.png', 15);
            } finally {
                Artisan::call('up');
                $tv->visit('/login');
                $tv->script('localStorage.clear();');
            }
        });
    }

    /* ── Helpers ─────────────────────────────────────────────────────── */

    /**
     * The server put into maintenance: every request it gets is a 503 — to the worker's network-first paths,
     * exactly what an unreachable server looks like. Checked from here before the television is watched, so a
     * maintenance file the server never saw cannot masquerade as a player fault.
     */
    private function serverGoesAway(): void
    {
        Artisan::call('down');

        $this->assertSame(503, $this->serverStatus(), 'the server did not go into maintenance');
    }

    private function serverComesBack(): void
    {
        Artisan::call('up');

        $this->assertSame(200, $this->serverStatus(), 'the server did not come back from maintenance');
    }

    /**
     * What the Dusk server answers the player page with, right now — 503 in maintenance, 200 otherwise.
     * (Laravel's own health check, /up, answers 200 even in maintenance, so it says nothing here.)
     */
    private function serverStatus(): int
    {
        $headers = @get_headers(rtrim((string) env('APP_URL', 'http://localhost:8001'), '/').'/player');

        return (int) (preg_match('/\s(\d{3})\s/', (string) ($headers[0] ?? ''), $match) ? $match[1] : 0);
    }

    /** A real picture in the store's library, on the Dusk disk, playable until $expiresAt (or for ever). */
    private function picture(Store $store, string $file, array $rgb, $expiresAt = null): Media
    {
        return Media::factory()->create([
            'store_id' => $store->id,
            'title' => pathinfo($file, PATHINFO_FILENAME),
            'type' => Media::TYPE_IMAGE,
            'mime_type' => 'image/png',
            'path' => $this->putImage("media/{$store->id}/{$file}", ...$rgb),
            'thumbnail_path' => null,
            'expires_at' => $expiresAt,
        ]);
    }

    /** The address of the picture on the glass, or '' while there is none. */
    private function onScreen(Browser $tv): string
    {
        return (string) $tv->script('return (document.querySelector("#layer-a:not([hidden]) img, #layer-b:not([hidden]) img") || {}).src || "";')[0];
    }

    private function waitForPicture(Browser $tv, string $file, int $seconds = 30): void
    {
        $tv->waitUsing($seconds, 250, fn () => str_contains($this->onScreen($tv), $file), "{$file} never came on the glass (showing: {$this->onScreen($tv)})");
    }

    /** Every address in the worker's media cache. */
    private function cachedMediaUrls(Browser $tv): array
    {
        return $tv->driver->executeAsyncScript(
            'const done = arguments[arguments.length - 1];'
            .'caches.open("signage-1-media").then((cache) => cache.keys()).then((keys) => done(keys.map((key) => key.url))).catch(() => done([]));'
        );
    }
}
