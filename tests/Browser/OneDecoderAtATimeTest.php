<?php

namespace Tests\Browser;

use App\Models\BuilderAd;
use App\Models\BuilderAsset;
use App\Models\Media;
use App\Models\Organization;
use App\Models\PlaylistItem;
use App\Models\Screen;
use App\Services\AdPublisher;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * One decoder at a time (player.js, the section of that name; the owner's FB 24 Deli TVs and the Philips TV in the
 * office, 2026-11-05). A video made ready behind the one on screen takes a second decoder, and a set may have only one —
 * for a 4K video most have one: the ELC TVs fell, black, every fifty seconds, and the Philips froze on the last picture
 * for sixteen. So the same video again plays again in its own element; a video bigger than full HD is never set up
 * beside another, the stage letting its decoders go first with its last pictures held as stills; a set that showed it
 * decodes one at a time remembers it; and a page made ready beside such a video gets its own videos only once it is on.
 *
 * A set with one decoder is played here by a page script (oneDecoder): a video that starts to load while another holds a
 * file fails as the Philips's did, "video decoder reinitialization failed" (MEDIA_ERR_DECODE).
 */
class OneDecoderAtATimeTest extends DuskTestCase
{
    use DatabaseMigrations;

    private const FIXTURES = 'tests/Browser/fixtures/video/';

    /** @var list<array{0: ChromeDevToolsDriver, 1: string}> */
    private array $pageScripts = [];

    protected function tearDown(): void
    {
        foreach ($this->pageScripts as [$tools, $identifier]) {
            try {
                $tools->execute('Page.removeScriptToEvaluateOnNewDocument', ['identifier' => $identifier]);
            } catch (\Throwable) {
                // The browser is gone already, and its scripts with it.
            }
        }
        $this->pageScripts = [];

        parent::tearDown();
    }

    public function test_a_video_alone_on_a_playlist_plays_again_in_its_own_element_from_its_start(): void
    {
        // FB 24 Deli Tv5's one 4K video: a copy of it was set up beside it 3.5 s before its end, a second decoder the TV
        // did not have. Now it is the one element, from its first frame again the moment it ends.
        [$organization, $screen] = $this->screen('alone-token');
        $this->video($organization, $screen, 'portrait-red-top-2s.mp4', 0, 2);

        $this->browse(function (Browser $tv) {
            $this->watch($tv);
            $this->startTheSet($tv, 'alone-token');

            $tv->waitUntil('window.__decoders.ended.length >= 3', 40, 'the video never ended three times');
            $g = $tv->script('return window.__decoders;')[0];

            $this->assertSame(1, $g['videosMade'], 'one video element, never a copy beside it');
            foreach (array_slice($g['ended'], 0, 3) as $end) {
                $next = collect($g['playing'])->first(fn (array $play): bool => $play[0] >= $end);
                $this->assertNotNull($next, 'it never played again after an end: '.json_encode($g['playing']));
                $this->assertLessThan(300, $next[0] - $end, 'it played again '.round($next[0] - $end).' ms after it ended');
                $this->assertLessThan(0.2, $next[1], 'it played again from its start, not from '.$next[1]);
            }
            $this->assertSame(0, $g['emptyAfterStart'], 'the glass was never empty');
        });
    }

    public function test_two_videos_the_server_measured_at_4k_are_never_decoded_at_once_and_a_still_holds_the_glass_between(): void
    {
        // FB 24 Deli Tv4's two 4K videos. The next is set up only once the one before has let its decoder go, its last
        // picture held as a still meanwhile: never two decoders, never an empty glass.
        [$organization, $screen] = $this->screen('four-k-token');
        $this->video($organization, $screen, 'portrait-red-top-2s.mp4', 0, 2, [3840, 2160]);
        $this->video($organization, $screen, 'portrait-red-top.mp4', 1, 4, [3840, 2160]);

        $this->browse(function (Browser $tv) {
            $this->watch($tv);
            $this->startTheSet($tv, 'four-k-token');

            $tv->waitUntil('window.__decoders.ended.length >= 4', 60, 'the videos never ended four times');
            $g = $tv->script('return window.__decoders;')[0];

            $this->assertSame(1, $g['mostAtOnce'], 'never two videos holding a decoder at once');
            $this->assertGreaterThanOrEqual(3, $g['stills'], 'the video before was held as a still at each switch');
            $this->assertSame(0, $g['emptyAfterStart'], 'the glass was never empty');
            foreach (array_slice($g['ended'], 0, 3) as $end) {
                $next = collect($g['playing'])->first(fn (array $play): bool => $play[0] >= $end);
                $this->assertNotNull($next, 'the next video never played');
                $this->assertLessThan(2000, $next[0] - $end, 'the next video played '.round($next[0] - $end).' ms after the end');
                $this->assertLessThan(0.2, $next[1], 'it played from its start');
            }
        });
    }

    public function test_a_set_that_decodes_one_video_at_a_time_learns_it_and_never_freezes_on_the_last_picture(): void
    {
        // The Philips TV: a second decoder refused, the switch waited sixteen seconds on the last picture. Now the video
        // that found no decoder is set up again with the stage let go, it plays, and the set knows from then on.
        [$organization, $screen] = $this->screen('one-decoder-token');
        $this->video($organization, $screen, 'portrait-red-top-2s.mp4', 0, 2);
        $this->video($organization, $screen, 'portrait-red-top.mp4', 1, 4);

        $this->browse(function (Browser $tv) {
            $this->watch($tv, oneDecoder: true);
            $this->startTheSet($tv, 'one-decoder-token');

            $tv->waitUntil('window.__decoders.ended.length >= 5', 60, 'the videos never ended five times');
            $g = $tv->script('return window.__decoders;')[0];

            $this->assertNotNull($g['learnedAt'], 'the set never learned it decodes one video at a time');
            $this->assertGreaterThanOrEqual(1, count($g['refusedAt']), 'the one-decoder set refused a second video');
            foreach ($g['refusedAt'] as $refused) {
                $this->assertLessThanOrEqual($g['learnedAt'], $refused, 'a second video was set up after the set had learned');
            }
            foreach (array_slice($g['ended'], 0, 4) as $end) {
                $next = collect($g['playing'])->first(fn (array $play): bool => $play[0] >= $end);
                $this->assertNotNull($next, 'the next video never played');
                $this->assertLessThan(2000, $next[0] - $end, 'the next video played '.round($next[0] - $end).' ms after the end');
            }
            $this->assertSame(0, $g['emptyAfterStart'], 'the glass was never empty');
        });
    }

    public function test_a_page_that_fell_while_a_second_video_was_set_up_makes_the_set_decode_one_at_a_time(): void
    {
        // An ELC TV's WebView fell setting the second decoder up, before any error could say so: the page coming back
        // within moments of it finds the mark it left, and the set decodes one video at a time.
        [$organization, $screen] = $this->screen('fell-token');
        $this->video($organization, $screen, 'portrait-red-top-2s.mp4', 0, 2);
        $this->video($organization, $screen, 'portrait-red-top.mp4', 1, 4);

        $this->browse(function (Browser $tv) {
            $this->watch($tv);
            $this->startTheSet($tv, 'fell-token', ['signage.second_decoder' => 'NOW']);

            $tv->waitUntil('window.__decoders.ended.length >= 3', 60, 'the videos never ended three times');
            $g = $tv->script('return window.__decoders;')[0];

            $this->assertNotEmpty($tv->script("return localStorage.getItem('signage.one_decoder');")[0], 'the set did not remember');
            $this->assertSame(1, $g['mostAtOnce'], 'a second video was set up beside one after all');
        });
    }

    public function test_a_page_made_ready_beside_a_4k_video_gets_its_own_video_only_once_it_is_on(): void
    {
        // An Ad Builder page over a background video, after a 4K video: made ready behind it, the page's video took the
        // decoder the one on screen held. The worker now sends the page with its videos' files taken out; the page has
        // them once it is on, and plays them.
        [$organization, $screen] = $this->screen('page-token');
        $this->video($organization, $screen, 'portrait-red-top.mp4', 0, 4, [3840, 2160]);
        $page = $this->page($organization, 'Over a video');
        PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $page->id, 'position' => 1, 'duration_seconds' => null]);

        $this->browse(function (Browser $tv) {
            $this->watch($tv);
            $this->startTheSet($tv, 'page-token', worker: true);

            $tv->waitUntil('window.__decoders.pages.some((p) => p.onAt && p.playingAfterOn)', 60, 'the page never came on with its video playing');
            $g = $tv->script('return window.__decoders;')[0];
            $page = collect($g['pages'])->first(fn (array $p): bool => isset($p['onAt']));

            $this->assertTrue($page['heldBehind'], 'its video had its file while the page was behind the 4K one: '.json_encode($g['pages']));
            $this->assertTrue($page['playingAfterOn'], 'its video never played once it was on');
            $this->assertSame(1, $g['mostAtOnce'], 'never two videos holding a decoder at once');
        });
    }

    /**
     * Before the page loads, in every frame: how many videos hold a file at once (the most, after the first came on),
     * every video's `playing` (with its time) and `ended`, how many <video> elements were made, the stills put up, the
     * moments the glass had nothing on it, each page's video behind and once on, and when the set learned it decodes one
     * at a time. With `oneDecoder`, a video that starts to load while another holds a file fails as a one-decoder set's.
     */
    private function watch(Browser $tv, bool $oneDecoder = false): void
    {
        $fake = $oneDecoder ? 'true' : 'false';
        $tools = new ChromeDevToolsDriver($tv->driver);
        $this->pageScripts[] = [$tools, (string) $tools->execute('Page.addScriptToEvaluateOnNewDocument', ['source' => <<<JS
            (() => {
                const ONE_DECODER = {$fake};
                const root = window.top;
                const g = root.__decoders = root.__decoders || {
                    mostAtOnce: 0, playing: [], ended: [], videosMade: 0, stills: 0, emptyAfterStart: 0, started: false,
                    refusedAt: [], learnedAt: null, pages: [], held: new Set(),
                };
                const holding = (v) => v.isConnected && !!v.getAttribute('src') && !v.__refused && !v.error;
                if (ONE_DECODER) {
                    document.addEventListener('loadstart', (event) => {
                        const video = event.target;
                        if (!video || video.tagName !== 'VIDEO') return;
                        for (const other of Array.from(g.held)) if (other === video || !holding(other)) g.held.delete(other);
                        if (g.held.size === 0) { g.held.add(video); return; }
                        video.__refused = true;
                        g.refusedAt.push(root.performance.now());
                        Object.defineProperty(video, 'error', { configurable: true, get: () => ({ code: 3, message: 'PIPELINE_ERROR_DECODE: video decoder reinitialization failed' }) });
                        setTimeout(() => { video.removeAttribute('src'); video.load(); video.dispatchEvent(new Event('error')); }, 20);
                    }, true);
                }
                if (window !== root) return;

                document.addEventListener('playing', (event) => { if (event.target.tagName === 'VIDEO') g.playing.push([performance.now(), event.target.currentTime]); }, true);
                document.addEventListener('ended', (event) => { if (event.target.tagName === 'VIDEO') g.ended.push(performance.now()); }, true);
                const framesOf = () => Array.from(document.querySelectorAll('iframe'));
                const pageVideo = (frame) => { try { return frame.contentDocument ? frame.contentDocument.querySelector('video, canvas') : null; } catch (e) { return null; } };
                const glassEmpty = () => {
                    const ad = document.getElementById('layer-ad');
                    if (ad && !ad.hidden) return false;
                    const front = document.querySelector('#layer-a:not([hidden]) > *, #layer-b:not([hidden]) > *');
                    if (!front) return true;
                    if (front.tagName === 'IMG') return !(front.complete && front.naturalWidth > 0);
                    // A video that has had a picture keeps it on the glass while it seeks back to its start (replay).
                    if (front.tagName === 'VIDEO') return front.readyState < 2 && !front.__hadAPicture;
                    return false;
                };
                document.addEventListener('loadeddata', (event) => { event.target.__hadAPicture = true; }, true);
                const seenPages = new Map();
                const watch = () => {
                    const videos = Array.from(document.querySelectorAll('video'));
                    framesOf().forEach((frame) => { try { if (frame.contentDocument) videos.push(...frame.contentDocument.querySelectorAll('video')); } catch (e) {} });
                    const now = videos.filter(holding).length;
                    if (g.started) {
                        g.mostAtOnce = Math.max(g.mostAtOnce, now);
                        if (glassEmpty()) g.emptyAfterStart += 1;
                    } else if (videos.some((v) => v.readyState >= 2) || document.querySelector('#layer-a:not([hidden]) > img, #layer-b:not([hidden]) > img')) {
                        g.started = true;
                    }
                    if (!g.learnedAt && localStorage.getItem('signage.one_decoder')) g.learnedAt = performance.now();
                    framesOf().forEach((frame) => {
                        const doc = (() => { try { return frame.contentDocument; } catch (e) { return null; } })();
                        if (!doc || !doc.querySelector('.ad-layer, video, canvas')) return;
                        const page = seenPages.get(frame) || { heldBehind: null, onAt: null, playingAfterOn: false };
                        seenPages.set(frame, page);
                        if (!g.pages.includes(page)) g.pages.push(page);
                        const video = doc.querySelector('video');
                        const behind = frame.closest('[hidden]') !== null;
                        if (behind && video && page.heldBehind === null && doc.readyState === 'complete') page.heldBehind = !video.getAttribute('src');
                        if (!behind && page.onAt === null) page.onAt = performance.now();
                        if (!behind && video && video.getAttribute('src') && video.currentTime > 0.3 && !video.paused) page.playingAfterOn = true;
                    });
                    requestAnimationFrame(watch);
                };
                // The page has no element yet when this runs: what it is made of is watched once it is there.
                document.addEventListener('DOMContentLoaded', () => {
                    new MutationObserver((list) => {
                        for (const m of list) for (const n of m.addedNodes) {
                            if (n.tagName === 'VIDEO') g.videosMade += 1;
                            if (n.tagName === 'CANVAS' && n.dataset && n.dataset.still) g.stills += 1;
                        }
                    }).observe(document.body, { childList: true, subtree: true });
                    requestAnimationFrame(watch);
                });
            })();
        JS])['identifier']];
    }

    /** A 1920 x 1080 panel paired as the screen with this token, with `stored` in its storage ('NOW' for the time now). */
    private function startTheSet(Browser $tv, string $token, array $stored = [], bool $worker = false): void
    {
        $tv->resize(1920, 1080);
        $tv->visit('/login');
        $writes = collect($stored)->map(fn (string $value, string $key): string => 'localStorage.setItem('.json_encode($key).', '.($value === 'NOW' ? 'String(Date.now())' : json_encode($value)).');')->implode(' ');
        $tv->script("localStorage.clear(); localStorage.setItem('signage.device.token', '{$token}'); {$writes}");
        $tv->visit('/player');

        if ($worker) {
            // The worker sends a page made ready beside a 4K video without its videos' files (§15): only where it keeps
            // the set.
            $tv->waitUntil('!!navigator.serviceWorker.controller', 20, 'no worker took the page over');
            $tv->script("localStorage.removeItem('signage.one_decoder');");
            $tv->refresh();
        }
    }

    /** @return array{0: Organization, 1: Screen} */
    private function screen(string $token): array
    {
        $organization = Organization::factory()->create(['name' => 'FB 24 Deli', 'accepts_network_ads' => false]);
        $screen = Screen::factory()->withToken($token)->create([
            'organization_id' => $organization->id, 'name' => 'Deli TV', 'orientation' => 'landscape', 'accepts_network_ads' => false,
        ]);

        return [$organization, $screen];
    }

    /** A video fixture on the playlist at `position`, measured by the server at `size` (its real one by default). */
    private function video(Organization $organization, Screen $screen, string $file, int $position, int $seconds, array $size = [360, 640]): Media
    {
        $path = "media/{$organization->id}/".Str::ulid().'-'.$file;
        Storage::disk('public')->put($path, file_get_contents(base_path(self::FIXTURES.$file)));
        $media = Media::factory()->video()->create([
            'organization_id' => $organization->id, 'title' => $file, 'path' => $path, 'thumbnail_path' => null,
            'size' => filesize(base_path(self::FIXTURES.$file)), 'width' => $size[0], 'height' => $size[1],
            'orientation' => $size[0] >= $size[1] ? 'landscape' : 'portrait', 'duration_seconds' => $seconds,
        ]);
        PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $media->id, 'position' => $position, 'duration_seconds' => null]);

        return $media;
    }

    /** A published ten-second Ad Builder page over a background video, its headline fading in. */
    private function page(Organization $organization, string $name): Media
    {
        $document = BuilderAd::blankDocument();
        $document['duration'] = 10;
        $path = "builder/{$organization->id}/assets/".Str::ulid().'.mp4';
        Storage::disk('public')->put($path, file_get_contents(base_path(self::FIXTURES.'portrait-red-top.mp4')));
        $clip = BuilderAsset::factory()->video()->create(['organization_id' => $organization->id, 'path' => $path, 'thumbnail_path' => null]);
        $document['stage']['background']['layers'] = [['id' => 'bg_video', 'type' => 'video', 'assetId' => $clip->id, 'fit' => 'cover', 'visible' => true, 'opacity' => 1]];
        $document['elements'] = [[
            'id' => 'el_text', 'type' => 'text', 'name' => 'Headline', 'text' => $name,
            'x' => 160, 'y' => 400, 'w' => 1600, 'h' => 240, 'rotation' => 0, 'opacity' => 1, 'z' => 0, 'locked' => false, 'visible' => true,
            'style' => ['fontSize' => 120, 'color' => '#ffffff'], 'animations' => ['in' => ['effect' => 'fade', 'duration' => 0.5]],
        ]];
        $ad = BuilderAd::create([
            'organization_id' => $organization->id, 'name' => $name, 'orientation' => BuilderAd::LANDSCAPE, 'document' => $document,
        ]);

        return app(AdPublisher::class)->publish($ad);
    }
}
