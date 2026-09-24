<?php

namespace Tests\Browser;

use App\Models\BuilderAd;
use App\Models\BuilderAsset;
use App\Models\Media;
use App\Models\PlaylistItem;
use App\Models\Screen;
use App\Models\Store;
use App\Services\AdPublisher;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Dusk\Browser;
use Symfony\Component\Process\Process;
use Tests\DuskTestCase;

/**
 * A television with no line (docs/AD-BUILDER-SPEC.md §15): paired and playing, its worker holds the page,
 * the manifest and every file; then the server goes away — put into maintenance, so every request it
 * gets is a 503 and the worker's network-first paths fall back to their caches, exactly as they do when
 * there is no network at all — and the set plays on: what has not expired, then the holding picture once
 * everything has, through a reboot, until the line returns and the server's own answer takes over.
 *
 * Real Chrome, a real worker, real files: nothing here can be vouched for by a feature test.
 *
 * The second test cuts the line for real: the television is served by a second server of its own (port
 * 8002, with a router that answers range requests the way nginx does), and that server is killed — so
 * nothing answers at all, not even a 503. The set then plays an ad page from its cache — the page opened
 * inside the worker's scope, and the picture and the runtime the page loads served by the worker too — puts
 * on a picture whose time comes while the line is down (the manifest's timeline), reboots with no line, and
 * goes live again when the server returns. A file the next days bring is fetched meanwhile in pieces.
 */
class OfflinePlayerTest extends DuskTestCase
{
    use DatabaseMigrations;

    /** Where the second test's television is served from: a server the test can kill. */
    private const LINE_ORIGIN = 'http://localhost:8002';

    private const LINE_PORT = 8002;

    /** The worker fetches a big file this many bytes at a time (public/player-sw.js, PART_BYTES). */
    private const PART_BYTES = 4 * 1024 * 1024;

    public function test_a_television_keeps_playing_from_its_cache_when_the_server_cannot_be_reached(): void
    {
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $screen = Screen::factory()->withToken('offline-token')->create(['store_id' => $store->id, 'name' => 'Counter TV']);

        $holding = $this->picture($store, 'holding.png', [30, 30, 30]);
        $screen->update(['default_media_id' => $holding->id]);

        // Two pictures with short lives: the first ends while the line is down, the second a while after.
        $soon = $this->picture($store, 'soon.png', [200, 40, 40], now()->addSeconds(50));
        $later = $this->picture($store, 'later.png', [40, 160, 60], now()->addSeconds(100));

        PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $soon->id, 'position' => 0, 'duration_seconds' => 3]);
        PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $later->id, 'position' => 1, 'duration_seconds' => 3]);

        $this->browse(function (Browser $tv) use ($soon, $later) {
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
                // The next poll, answered from memory, plays the timeline's entry for now — the one from the moment
                // the first picture expired, which the server worked out without it (§15): nothing needs dropping.
                $tv->waitUsing(60, 500, fn () => (int) $tv->script('return Number(document.body.dataset.entry || 0);')[0] > 0,
                    'after the first picture expired, the cached manifest did not move on to the timeline entry without it');
                $this->assertSame('', $tv->script('return document.body.dataset.dropped;')[0], 'the timeline had already left it out');

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

    public function test_with_its_line_cut_a_television_plays_its_ad_page_and_everything_the_page_loads_from_its_cache(): void
    {
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $screen = Screen::factory()->withToken('line-token')->create(['store_id' => $store->id, 'name' => 'Window TV']);

        // An ad page with a picture of its own and an entrance, so the frame loads the picture AND the runtime.
        $page = $this->publishedPage($store);

        // A picture whose time comes while the line is down: only the timeline says when (§15).
        $later = $this->picture($store, 'later.png', [40, 160, 60]);
        $later->update(['starts_at' => now()->addSeconds(100)]);

        // A file the next days bring — tomorrow — bigger than two pieces: warmed now, played by no one here.
        $big = $this->bigFile($store, 'tomorrow.mp4', 2 * self::PART_BYTES + 123_456, now()->addDay());

        PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $page->media_id, 'position' => 0, 'duration_seconds' => 4]);
        PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $later->id, 'position' => 1, 'duration_seconds' => 3]);
        PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $big->id, 'position' => 2]);

        $server = $this->startLine();

        $this->browse(function (Browser $tv) use (&$server, $later, $big) {
            try {
                $tv->visit(self::LINE_ORIGIN.'/up');
                $tv->script("localStorage.clear(); localStorage.setItem('signage.device.token', 'line-token');");
                $tv->visit(self::LINE_ORIGIN.'/player');

                /* ── 1. Live: a worker takes the page over and warms everything, the big file in pieces ── */
                $tv->waitUntil('!!navigator.serviceWorker.controller', 20, 'no worker took the page over');
                $tv->waitUntil('!!document.body.dataset.warmed', 60, 'the worker never said the cache was warmed');
                $this->assertSame('0', $tv->script('return document.body.dataset.warmMissing;')[0], 'every file the next days bring was fetched');

                $this->assertSame($big->size, $this->cachedSize($tv, 'tomorrow.mp4'), 'the big file is kept whole');
                $this->assertSame(0, $this->cachedCount($tv, 'signage-parts'), 'no piece is left once the file is whole');
                $this->assertSame(3, preg_match_all('#range \d+-\d+/'.$big->size.' \S*tomorrow\.mp4#', $server->getErrorOutput()),
                    'the big file came in three pieces');

                // The page plays in a frame opened inside the worker's scope — not as srcdoc, which only Chrome 135
                // and later serves from a worker — with its picture and its runtime.
                $this->waitForPage($tv, 'while live');
                $this->assertStringStartsWith('/player/page?src=', $tv->script('return document.querySelector("#layer-a:not([hidden]) iframe, #layer-b:not([hidden]) iframe").getAttribute("src");')[0]);

                /* ── 2. The line is cut: nothing answers at all ── */
                $this->assertTrue(now()->lt($later->starts_at), 'the line was cut after the later picture’s time: the test proves nothing about the timeline');
                $this->cutLine($server);
                $server = null;

                $tv->waitUntil('document.body.dataset.source === "cache"', 75, 'with the line cut, no manifest came from the cache');

                // A frame made AFTER the cut: its page, its picture and its runtime can only have come from the cache.
                $tv->script('document.querySelectorAll("#layer-a iframe, #layer-b iframe").forEach((frame) => { frame.dataset.before = "1"; });');
                $this->waitForPage($tv, 'with the line cut', true);

                /* ── 3. The later picture's time comes with the line still down: the timeline puts it on ── */
                $tv->waitUsing(120, 250, fn () => str_contains($this->onScreen($tv), 'later.png'),
                    'the picture whose time came while the line was down never came on');

                /* ── 4. Rebooted with no line: its page and its scripts from the cache, and the ad page plays ── */
                $tv->refresh();
                $tv->waitUntil('document.body.dataset.source === "cache"', 45, 'rebooted with no line, the page did not play from the cache');
                $this->waitForPage($tv, 'after a reboot with no line');

                /* ── 5. The line comes back: the server's own answer, and the set is live again ── */
                $server = $this->startLine();
                $tv->waitUntil('document.body.dataset.source === "live"', 75, 'with the line back, the set did not go live again');
            } finally {
                if ($server !== null) {
                    $this->cutLine($server);
                }
            }
        });
    }

    /* ── Helpers ─────────────────────────────────────────────────────── */

    /**
     * The second server: PHP's built-in one on LINE_PORT with the range router, serving the same Dusk database
     * and disk, told its own address — so every file address it hands out is one on the line it can cut.
     */
    private function startLine(): Process
    {
        $process = new Process(
            [PHP_BINARY, '-S', 'localhost:'.self::LINE_PORT, base_path('tests/Browser/support/range-router.php')],
            public_path(),
            ['APP_URL' => self::LINE_ORIGIN],
        );
        $process->setTimeout(null);
        $process->start();

        $deadline = microtime(true) + 15;

        while (! $this->lineAnswers()) {
            if (microtime(true) > $deadline) {
                $process->stop(0);
                $this->fail('the second server never answered: '.$process->getErrorOutput());
            }

            usleep(200_000);
        }

        return $process;
    }

    /** The second server killed — and checked: the set must meet a line that is down, not a slow server. */
    private function cutLine(Process $process): void
    {
        $process->stop(0);

        $deadline = microtime(true) + 10;

        while ($this->lineAnswers() && microtime(true) < $deadline) {
            usleep(200_000);
        }

        $this->assertFalse($this->lineAnswers(), 'the second server is still answering');
    }

    private function lineAnswers(): bool
    {
        foreach (['127.0.0.1', '[::1]'] as $host) {
            $socket = @fsockopen($host, self::LINE_PORT, $code, $message, 0.5);

            if ($socket !== false) {
                fclose($socket);

                return true;
            }
        }

        return false;
    }

    /**
     * A published ad page with a picture of its own and a headline that fades in — compiled at the second
     * server's address, so every picture and script in it is one the set fetches from there.
     */
    private function publishedPage(Store $store): BuilderAd
    {
        $asset = BuilderAsset::factory()->create([
            'store_id' => $store->id,
            'title' => 'Shop front',
            'mime_type' => 'image/png',
            'path' => $this->putImage("builder/{$store->id}/assets/shop-front.png", 200, 120, 40),
            'thumbnail_path' => null,
            'width' => 640,
            'height' => 360,
        ]);

        $document = BuilderAd::blankDocument();
        $document['elements'] = [
            ['id' => 'photo', 'type' => 'image', 'name' => 'Photo', 'assetId' => $asset->id, 'x' => 0, 'y' => 0, 'w' => 960, 'h' => 1080,
                'rotation' => 0, 'opacity' => 1, 'z' => 0, 'locked' => false, 'visible' => true, 'style' => [], 'animations' => []],
            ['id' => 'title', 'type' => 'text', 'name' => 'Headline', 'text' => 'Open all weekend', 'x' => 1000, 'y' => 420, 'w' => 860, 'h' => 200,
                'rotation' => 0, 'opacity' => 1, 'z' => 1, 'locked' => false, 'visible' => true,
                'style' => ['fontSize' => 96, 'color' => '#ffffff'], 'animations' => ['in' => ['effect' => 'fade', 'duration' => 0.5]]],
        ];

        $ad = BuilderAd::factory()->create(['store_id' => $store->id, 'name' => 'Open all weekend', 'document' => $document, 'in_playlists' => true]);

        $diskUrl = config('filesystems.disks.public.url');
        URL::forceRootUrl(self::LINE_ORIGIN);
        config(['filesystems.disks.public.url' => self::LINE_ORIGIN.'/dusk-storage']);
        Storage::forgetDisk('public');

        try {
            app(AdPublisher::class)->publish($ad);
        } finally {
            URL::forceRootUrl(null);
            config(['filesystems.disks.public.url' => $diskUrl]);
            Storage::forgetDisk('public');
        }

        return $ad->fresh();
    }

    /** A file of $bytes on the Dusk disk, in the store's library, playable from $startsAt. */
    private function bigFile(Store $store, string $file, int $bytes, $startsAt): Media
    {
        $path = "media/{$store->id}/{$file}";
        Storage::disk('public')->put($path, str_repeat('signage ', intdiv($bytes, 8)).str_repeat('.', $bytes % 8));

        return Media::factory()->create([
            'store_id' => $store->id,
            'title' => pathinfo($file, PATHINFO_FILENAME),
            'type' => Media::TYPE_VIDEO,
            'mime_type' => 'video/mp4',
            'path' => $path,
            'thumbnail_path' => null,
            'size' => $bytes,
            'duration_seconds' => 30,
            'starts_at' => $startsAt,
        ]);
    }

    /**
     * Wait until the ad page is on the glass with its picture drawn and its headline faded in — in a frame made
     * after the line was cut when $afterTheCut (every frame on the page then was marked before it).
     */
    private function waitForPage(Browser $tv, string $when, bool $afterTheCut = false): void
    {
        $fresh = $afterTheCut ? 'if (frame.dataset.before) return false;' : '';

        $tv->waitUsing(60, 250, fn () => (bool) $tv->script(<<<JS
            const frame = document.querySelector('#layer-a:not([hidden]) iframe, #layer-b:not([hidden]) iframe');
            if (!frame) return false;
            {$fresh}
            const page = frame.contentDocument;
            const picture = page && page.querySelector('img');
            const headline = page && page.querySelector('[data-anim-id="title"] > .ad-anim');
            return !!(picture && picture.complete && picture.naturalWidth > 0 && frame.contentWindow.AdRuntime && frame.contentWindow.anime
                && headline && getComputedStyle(headline).opacity === '1');
            JS)[0], "the ad page, its picture and its headline never all came on {$when}");
    }

    /** How big the kept copy of a file is in the worker's media cache, or -1 when there is none. */
    private function cachedSize(Browser $tv, string $file): int
    {
        return (int) $tv->driver->executeAsyncScript(
            'const done = arguments[arguments.length - 1];'
            .'caches.open("signage-media").then(async (cache) => {'
            .'  const key = (await cache.keys()).find((request) => request.url.includes('.json_encode($file).'));'
            .'  done(key ? (await (await cache.match(key)).blob()).size : -1);'
            .'}).catch(() => done(-2));'
        );
    }

    /** How many entries one of the worker's caches holds. */
    private function cachedCount(Browser $tv, string $cache): int
    {
        return (int) $tv->driver->executeAsyncScript(
            'const done = arguments[arguments.length - 1];'
            .'caches.open('.json_encode($cache).').then((cache) => cache.keys()).then((keys) => done(keys.length)).catch(() => done(-1));'
        );
    }

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
            .'caches.open("signage-media").then((cache) => cache.keys()).then((keys) => done(keys.map((key) => key.url))).catch(() => done([]));'
        );
    }
}
