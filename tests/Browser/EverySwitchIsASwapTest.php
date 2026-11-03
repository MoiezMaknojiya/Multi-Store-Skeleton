<?php

namespace Tests\Browser;

use App\Models\BuilderAd;
use App\Models\BuilderAsset;
use App\Models\Campaign;
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
 * Every switch on a television is a swap (docs/TV-ORIENTATION-SPEC.md §12.15): whatever comes next — a picture, an Ad
 * Builder page, a video, an advert — is made ready behind what is on screen, and the screen changes only once it is
 * ready. Measured on the owner's Fire TV Stick (2026-11-02), the switches that were not held the item before for up to
 * 0.6 s (a video before a page), went black between two adverts for a third of a second, and showed the item before a
 * break again for a moment as the break ended.
 *
 * What is on the glass is read where the player leaves it — in each moment the page is left in (a MutationObserver's
 * turn, before anything is painted) and every frame (requestAnimationFrame) — so a state that lasts no time at all on a
 * fast desk still counts: on a television it is a frame or more.
 */
class EverySwitchIsASwapTest extends DuskTestCase
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

    public function test_two_adverts_follow_each_other_with_nothing_between_and_the_break_hands_straight_over_to_the_next_item(): void
    {
        // A picture advert, then a video one: the video before the change came on black until its first frame, because
        // the layer was emptied first. And when the break was over the advert layer went first, and the picture from
        // before the break came back until the next one had loaded.
        [$organization, $screen] = $this->screen('swap-ads-token', acceptsAds: true);
        $this->picture($organization, $screen, 'before', [200, 40, 40], 0);
        $this->picture($organization, $screen, 'after', [40, 160, 60], 1);

        Campaign::factory()->lasting(10)->create([
            'name' => 'Still advert', 'path' => $this->putImage('campaigns/still-advert.png', 230, 200, 0),
            'thumbnail_path' => null, 'mime_type' => 'image/png',
        ])->screens()->attach($screen);
        $video = 'campaigns/moving-advert.mp4';
        Storage::disk('public')->put($video, file_get_contents(base_path(self::FIXTURES.'portrait-red-top.mp4')));
        Campaign::factory()->video(4)->create([
            'name' => 'Moving advert', 'path' => $video, 'thumbnail_path' => null,
        ])->screens()->attach($screen);

        $this->browse(function (Browser $tv) {
            $this->watchTheGlass($tv);
            $this->startTheSet($tv, 'swap-ads-token');

            // The break comes due during the first picture (every six seconds here) and plays after it: the still
            // advert, the moving one, then the next picture.
            $tv->waitUntil("window.__glass.seen.some((s) => s.includes('after.png'))", 60, 'the picture after the break never came on');

            $seen = $tv->script('return window.__glass.seen;')[0];
            $from = array_search(true, array_map(fn (string $s) => str_contains($s, 'before.png'), $seen), true);
            $this->assertNotFalse($from, 'the picture before the break was never seen: '.json_encode($seen));

            $this->assertSame(['before.png', 'ad still-advert.png', 'ad moving-advert.mp4', 'after.png'], array_map(
                fn (string $s) => preg_replace('#^(ad )?.*/#', '$1', $s),
                $this->withoutRepeats(array_slice($seen, $from)),
            ), 'the glass went: '.json_encode($this->withoutRepeats(array_slice($seen, $from))));

            $this->assertSame(0, $tv->script('return document.getElementById("layer-ad").children.length;')[0], 'the advert layer is emptied when it goes');
        });
    }

    public function test_an_ad_page_and_a_picture_are_made_ready_behind_the_item_before_and_the_page_starts_when_it_comes_on(): void
    {
        // A page was asked for only at the switch: the item before stood on the glass while it loaded (0.42 to 0.61 s
        // after a video on the stick). Now it is laid out behind, unseen and at its full size, and its entrance waits
        // until it is on screen.
        [$organization, $screen] = $this->screen('swap-page-token');
        $this->picture($organization, $screen, 'first', [200, 40, 40], 0);
        $page = $this->page($organization, 'Lunch special', withVideo: true);
        PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $page->id, 'position' => 1, 'duration_seconds' => null]);
        $this->picture($organization, $screen, 'third', [40, 160, 60], 2);

        $this->browse(function (Browser $tv) {
            $this->watchTheGlass($tv);
            $this->startTheSet($tv, 'swap-page-token', worker: true);

            $tv->waitUntil("window.__glass.seen.some((s) => s.includes('third.png'))", 60, 'the picture after the page never came on');
            $tv->waitUntil('window.__glass.pages.length > 0 && window.__glass.pages[0].later !== undefined', 5, 'the page was never looked at after it came on');

            // Each came on some seconds after it was put behind the item before — made ready, not made at the switch.
            $shown = $tv->script('return window.__glass.shown;')[0];
            foreach (['index.html', 'third.png'] as $name) {
                $switch = collect($shown)->first(fn (array $row) => str_contains($row['name'], $name));
                $this->assertNotNull($switch, "{$name} never came on: ".json_encode($shown));
                $this->assertGreaterThan(2500, $switch['behindFor'], "{$name} was made only ".round($switch['behindFor']).' ms before it came on: '.json_encode($shown));
                $this->assertTrue($switch['ready'], "{$name} came on before it was ready: ".json_encode($switch));
            }

            // Behind, the page was laid out at the panel's size and its entrance had not begun; on screen, it began.
            $page = $tv->script('return window.__glass.pages[0];')[0];
            $this->assertGreaterThan(0, $page['behindWidth'], 'the page behind was laid out with no size: '.json_encode($page));
            $this->assertGreaterThan(0, $page['pendingBehind'], 'the page\'s entrance played behind the picture: '.json_encode($page));
            $this->assertGreaterThan(0, $page['pendingAtSwitch'], 'the page\'s entrance had played before it came on: '.json_encode($page));
            $this->assertSame(0, $page['later'], 'the page\'s entrance never began once it was on screen: '.json_encode($page));

            // Its background video waited too: paused behind, and on screen from its start (it came on three seconds in
            // on the stick before the page held it).
            $this->assertTrue($page['videoPausedBehind'], 'the page\'s video played behind the picture: '.json_encode($page));
            $this->assertLessThan(0.5, $page['videoAtSwitch'], 'the page\'s video came on part-way through: '.json_encode($page));
            $this->assertGreaterThan(0.5, $page['videoLater'], 'the page\'s video never played once it was on screen: '.json_encode($page));

            $this->assertNotContains('NOTHING', array_slice($tv->script('return window.__glass.seen;')[0], 1), 'the glass was empty at a switch');
        });
    }

    public function test_a_landscape_screens_next_video_is_ready_behind_the_one_on_screen_so_the_switch_is_a_swap(): void
    {
        // A plain <video> was made only at the `ended` of the one before: 0.28 s of its last picture on the stick.
        [$organization, $screen] = $this->screen('swap-video-token');
        foreach (['portrait-red-top-2s.mp4', 'portrait-red-top.mp4'] as $position => $file) {
            $path = "media/{$organization->id}/{$file}";
            Storage::disk('public')->put($path, file_get_contents(base_path(self::FIXTURES.$file)));
            $media = Media::factory()->video()->create([
                'organization_id' => $organization->id, 'title' => $file, 'path' => $path, 'thumbnail_path' => null,
                'size' => filesize(base_path(self::FIXTURES.$file)), 'width' => 360, 'height' => 640, 'orientation' => 'portrait',
                'duration_seconds' => str_contains($file, '2s') ? 2 : 4,
            ]);
            PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $media->id, 'position' => $position, 'duration_seconds' => null]);
        }

        $this->browse(function (Browser $tv) {
            $this->watchTheGlass($tv);
            $this->startTheSet($tv, 'swap-video-token');

            $tv->waitUntil('window.__glass.ended.length >= 4', 40, 'the videos never ended four times');
            $switches = $tv->script(<<<'JS'
                const g = window.__glass;
                return g.ended.slice(0, 4).map((at) => {
                    const next = g.shown.find((s) => s.at >= at && s.tag === 'VIDEO');
                    return next ? [next.at - at, next.currentTime, next.behindFor] : [99999, 99999, 0];
                });
            JS)[0];

            foreach ($switches as [$gap, $from, $behindFor]) {
                $this->assertLessThan(100, $gap, 'the next video came on '.round($gap).' ms after the last one ended: '.json_encode($switches));
                $this->assertLessThan(0.15, $from, 'it came on from its first frame: '.json_encode($switches));
                $this->assertGreaterThan(500, $behindFor, 'it was made only at the switch: '.json_encode($switches));
            }
            $this->assertLessThanOrEqual(2, $tv->script('return document.querySelectorAll("video").length;')[0], 'no more than two videos at once');
        });
    }

    public function test_a_screen_set_to_fade_dissolves_each_item_and_each_advert_in_over_the_one_before(): void
    {
        // The owner's setting (2026-11-02 — "fade setting mein rakho"): with Fade, the next item, ready behind the one on
        // screen, fades in over it in half a second, and the one before goes only then; a break's adverts fade in over
        // the content and fade away over the item after them. Fade is every screen's own since 2026-11-03, so the other tests here
        // play with it too.
        [$organization, $screen] = $this->screen('swap-fade-token', acceptsAds: true);
        $screen->update(['transition' => 'fade']);
        $this->picture($organization, $screen, 'before', [200, 40, 40], 0);
        $this->picture($organization, $screen, 'after', [40, 160, 60], 1);
        Campaign::factory()->lasting(10)->create([
            'name' => 'Still advert', 'path' => $this->putImage('campaigns/still-advert.png', 230, 200, 0),
            'thumbnail_path' => null, 'mime_type' => 'image/png',
        ])->screens()->attach($screen);

        $this->browse(function (Browser $tv) {
            $tv->resize(1920, 1080);
            $tv->visit('/login');
            $tv->script("localStorage.clear(); localStorage.setItem('signage.device.token', 'swap-fade-token');");
            $tv->visit('/player');
            // Every frame: each layer's opacity while it is up, from the first picture on.
            $tv->script(<<<'JS'
                window.__fade = [];
                (function watch() {
                    const up = ['layer-a', 'layer-b', 'layer-ad'].map((id) => document.getElementById(id))
                        .filter((layer) => !layer.hidden && layer.firstElementChild)
                        .map((layer) => [layer.id, Number(getComputedStyle(layer).opacity).toFixed(2), (layer.firstElementChild.getAttribute('src') || '').split('?')[0].split('/').pop()]);
                    window.__fade.push([performance.now(), up]);
                    requestAnimationFrame(watch);
                })();
            JS);

            $tv->waitUntil("window.__fade.some(([, up]) => up.some((l) => l[2] === 'after.png' && l[1] === '1.00') && up.length === 1)", 60, 'the picture after the break never stood alone on the glass');
            $frames = $tv->script('return window.__fade;')[0];

            // The advert faded in over the first picture: both up, the advert part-way.
            $this->assertNotEmpty(array_filter($frames, fn (array $f) => $this->has($f[1], 'still-advert.png', partWay: true) && $this->has($f[1], 'before.png')),
                'the advert never faded in over the picture before it');
            // And faded away over the picture after it: both up, the advert part-way.
            $this->assertNotEmpty(array_filter($frames, fn (array $f) => $this->has($f[1], 'still-advert.png', partWay: true) && $this->has($f[1], 'after.png')),
                'the advert never faded away over the picture after it');
            // Each fade took about half a second, the item before up all along it.
            $this->assertEqualsWithDelta(500, $this->longestFade($frames, 'still-advert.png'), 200, 'a fade should last about half a second');
            // Never an empty glass from the first picture on.
            $first = array_search(true, array_map(fn (array $f) => $this->has($f[1], 'before.png'), $frames), true);
            $this->assertEmpty(array_filter(array_slice($frames, (int) $first), fn (array $f) => $f[1] === []), 'the glass was empty during a fade');
        });
    }

    public function test_on_a_slow_line_a_fading_screen_holds_what_it_shows_until_the_next_item_is_in_and_never_goes_black(): void
    {
        // Every kind at once on a line with 300 ms of latency and 400 KB a second, the files not yet in the worker's
        // cache: a picture, a page over a video, a video, a break with a picture advert, all fading. A file late to
        // arrive is waited for with the item before still up — the glass is never empty and never black.
        [$organization, $screen] = $this->screen('swap-slow-token', acceptsAds: true);
        $screen->update(['transition' => 'fade']);
        $this->picture($organization, $screen, 'first', [200, 40, 40], 0);
        $page = $this->page($organization, 'Slow special', withVideo: true);
        PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $page->id, 'position' => 1, 'duration_seconds' => null]);
        $path = "media/{$organization->id}/slow-clip.mp4";
        Storage::disk('public')->put($path, file_get_contents(base_path(self::FIXTURES.'portrait-red-top.mp4')));
        $clip = Media::factory()->video()->create([
            'organization_id' => $organization->id, 'title' => 'Slow clip', 'path' => $path, 'thumbnail_path' => null,
            'size' => filesize(base_path(self::FIXTURES.'portrait-red-top.mp4')), 'duration_seconds' => 4,
        ]);
        PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $clip->id, 'position' => 2, 'duration_seconds' => null]);
        Campaign::factory()->lasting(10)->create([
            'name' => 'Still advert', 'path' => $this->putImage('campaigns/still-advert.png', 230, 200, 0),
            'thumbnail_path' => null, 'mime_type' => 'image/png',
        ])->screens()->attach($screen);

        $this->browse(function (Browser $tv) {
            $this->watchTheGlass($tv);
            $this->network($tv, latency: 300, download: 400 * 1024);
            $this->startTheSet($tv, 'swap-slow-token');

            // A whole round and the break: the picture, the page, the clip, the advert, and the picture again.
            $tv->waitUntil("window.__glass.seen.filter((s) => s.includes('first.png')).length >= 2 && window.__glass.seen.some((s) => s.includes('still-advert.png'))", 90, 'the screen never went round once with its break');

            $seen = $tv->script('return window.__glass.seen;')[0];
            $from = array_search(true, array_map(fn (string $s) => str_contains($s, 'first.png'), $seen), true);
            $this->assertNotContains('NOTHING', array_slice($seen, (int) $from), 'the glass was empty on the slow line: '.json_encode($this->withoutRepeats($seen)));
            foreach (['index.html', 'slow-clip.mp4', 'ad campaigns/still-advert.png'] as $name) {
                $this->assertNotEmpty(array_filter($seen, fn (string $s) => str_contains($s, $name)), "{$name} never came on: ".json_encode($this->withoutRepeats($seen)));
            }

            $this->network($tv);
        });
    }

    public function test_the_transition_is_chosen_in_the_screens_edit_dialog(): void
    {
        $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->organizationMember($organization);
        $screen = Screen::factory()->create(['organization_id' => $organization->id, 'name' => 'Counter TV']);

        $this->browse(function (Browser $browser) use ($owner, $screen) {
            $browser->loginAs($owner)->visit('/screens')
                ->waitFor('@edit-screen-'.$screen->id)->click('@edit-screen-'.$screen->id)
                ->waitFor('@screen-edit-transition')->pause(300)
                ->assertSelected('@screen-edit-transition', 'fade')
                ->assertSee('How the screen changes from one item to the next.')
                ->select('@screen-edit-transition', 'none')
                ->click('@screen-save')
                ->waitUntilMissing('@screen-edit-transition');

            $this->assertSame('none', $screen->fresh()->transition);

            // Opened again on a fresh page, it says what was saved.
            $browser->refresh()->waitFor('@edit-screen-'.$screen->id)->click('@edit-screen-'.$screen->id)
                ->waitFor('@screen-edit-transition')->pause(300)
                ->assertSelected('@screen-edit-transition', 'none');
        });
    }

    /** Whether a frame has the layer holding $name up — with `partWay`, at an opacity between nothing and whole. */
    private function has(array $up, string $name, bool $partWay = false): bool
    {
        foreach ($up as [, $opacity, $file]) {
            if ($file === $name && (! $partWay || ((float) $opacity > 0.02 && (float) $opacity < 0.98))) {
                return true;
            }
        }

        return false;
    }

    /** The longest run, in ms, of frames in which the layer holding $name is up at an opacity part-way. */
    private function longestFade(array $frames, string $name): float
    {
        $longest = 0;
        $from = null;
        foreach ($frames as [$at, $up]) {
            if ($this->has($up, $name, partWay: true)) {
                $from ??= $at;
                $longest = max($longest, $at - $from);
            } else {
                $from = null;
            }
        }

        return $longest;
    }

    /**
     * Before the page loads: every moment the page is left in, and every frame, what is on the glass — the advert on
     * screen, else the organization's item on screen, by its file's name, or NOTHING when what is up has nothing to show
     * yet (a picture not loaded, a video without its first frame) — and for each item that comes on, how long it had
     * been behind and whether it was ready; for a page, how it stood behind, as it came on and a second later; and when
     * each video ended.
     */
    private function watchTheGlass(Browser $tv): void
    {
        $tools = new ChromeDevToolsDriver($tv->driver);
        $this->pageScripts[] = [$tools, (string) $tools->execute('Page.addScriptToEvaluateOnNewDocument', ['source' => <<<'JS'
            (() => {
                if (window.top !== window) return;
                const g = window.__glass = { seen: [], shown: [], pages: [], ended: [] };
                const nameOf = (n) => {
                    const src = (n.dataset && (n.dataset.src || n.dataset.item)) || n.getAttribute('src') || n.tagName;
                    return src.split('?')[0].split('/').slice(-2).join('/');
                };
                const ready = (n) => n.tagName === 'IMG' ? n.complete && n.naturalWidth > 0
                    : n.tagName === 'IFRAME' ? !!n.getAttribute('src') : n.readyState >= 2;
                const glass = () => {
                    const ad = document.getElementById('layer-ad');
                    if (ad && !ad.hidden) {
                        const up = [...ad.children].find((c) => !c.hidden);
                        return up && ready(up) ? 'ad ' + nameOf(up) : 'NOTHING';
                    }
                    const front = document.querySelector('#layer-a:not([hidden]) > *, #layer-b:not([hidden]) > *');
                    if (!front) return 'NOTHING';
                    return ready(front) ? nameOf(front) : 'NOTHING';
                };
                const note = () => {
                    const now = glass();
                    if (g.seen[g.seen.length - 1] !== now) g.seen.push(now);
                };
                const added = new WeakMap();
                const frames = [];
                const videoOf = (frame) => {
                    try { return frame.contentDocument ? frame.contentDocument.querySelector('video') : null; } catch (e) { return null; }
                };
                const pending = (frame) => {
                    try { return frame.contentDocument ? frame.contentDocument.querySelectorAll('.ad-pending').length : -1; } catch (e) { return -1; }
                };
                const watch = () => {
                    for (const id of ['layer-a', 'layer-b', 'layer-ad']) {
                        const layer = document.getElementById(id);
                        if (!layer || layer.__glass) continue;
                        layer.__glass = true;
                        new MutationObserver((records) => {
                            for (const r of records) for (const n of r.addedNodes) if (n.tagName) added.set(n, performance.now());
                            const up = layer.firstElementChild;
                            if (id !== 'layer-ad' && !layer.hidden && up && !up.__shown) {
                                up.__shown = true;
                                g.shown.push({ name: nameOf(up), tag: up.tagName, at: performance.now(), behindFor: performance.now() - (added.get(up) || performance.now()), ready: ready(up), currentTime: up.currentTime || 0 });
                                if (up.tagName === 'IFRAME') {
                                    const page = g.pages[frames.indexOf(up)] || {};
                                    page.pendingAtSwitch = pending(up);
                                    page.videoAtSwitch = videoOf(up) ? videoOf(up).currentTime : null;
                                    setTimeout(() => { page.later = pending(up); page.videoLater = videoOf(up) ? videoOf(up).currentTime : null; }, 1500);
                                }
                            }
                            note();
                        }).observe(layer, { attributes: true, attributeFilter: ['hidden'], childList: true, subtree: true });
                    }
                    // A page waiting behind: how it is laid out, and whether its entrance has begun.
                    for (const frame of document.querySelectorAll('#layer-a[hidden] iframe, #layer-b[hidden] iframe')) {
                        if (frame.__glass || pending(frame) < 0 || !frame.contentDocument.getElementById('ad-stage')) continue;
                        frame.__glass = true;
                        frames.push(frame);
                        g.pages.push({ behindWidth: frame.contentWindow.innerWidth, pendingBehind: pending(frame) });
                        // A second on, its video still waits at its start.
                        const page = g.pages[g.pages.length - 1];
                        setTimeout(() => { const v = videoOf(frame); page.videoPausedBehind = !!v && v.paused && v.currentTime < 0.1 && frame.closest('[hidden]') !== null; }, 1000);
                    }
                    note();
                    requestAnimationFrame(watch);
                };
                window.addEventListener('ended', (event) => { if (event.target.tagName === 'VIDEO') g.ended.push(performance.now()); }, true);
                document.addEventListener('DOMContentLoaded', watch);
            })();
        JS])['identifier']];
    }

    /** A 1920 x 1080 panel paired as the screen with this token; with `worker`, once its worker keeps the page. */
    private function startTheSet(Browser $tv, string $token, bool $worker = false): void
    {
        $tv->resize(1920, 1080);
        $tv->visit('/login');
        $tv->script("localStorage.clear(); localStorage.setItem('signage.device.token', '{$token}');");
        $tv->visit('/player');

        if ($worker) {
            // A page plays in a frame of the player's own origin only where the worker keeps the set (§15): then it can
            // be looked into from here.
            $tv->waitUntil('!!navigator.serviceWorker.controller', 20, 'no worker took the page over');
            $tv->refresh();
        }
    }

    /** @return array{0: Organization, 1: Screen} */
    private function screen(string $token, bool $acceptsAds = false): array
    {
        $organization = Organization::factory()->create(['name' => 'Alpha Mart', 'accepts_network_ads' => $acceptsAds]);
        $screen = Screen::factory()->withToken($token)->create([
            'organization_id' => $organization->id, 'name' => 'Counter TV', 'orientation' => 'landscape', 'accepts_network_ads' => $acceptsAds,
        ]);

        return [$organization, $screen];
    }

    /** A picture of one colour on the playlist at `position`, for ten seconds. */
    private function picture(Organization $organization, Screen $screen, string $name, array $rgb, int $position): Media
    {
        $picture = Media::factory()->create([
            'organization_id' => $organization->id, 'title' => $name, 'type' => Media::TYPE_IMAGE, 'mime_type' => 'image/png',
            'path' => $this->putImage("media/{$organization->id}/{$name}.png", ...$rgb), 'thumbnail_path' => null,
        ]);
        PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $picture->id, 'position' => $position, 'duration_seconds' => 10]);

        return $picture;
    }

    /** A published ten-second Ad Builder page whose headline fades in — `withVideo`, over a background video. */
    private function page(Organization $organization, string $name, bool $withVideo = false): Media
    {
        $document = BuilderAd::blankDocument();
        $document['duration'] = 10;
        if ($withVideo) {
            $path = "builder/{$organization->id}/assets/".Str::ulid().'.mp4';
            Storage::disk('public')->put($path, file_get_contents(base_path(self::FIXTURES.'portrait-red-top.mp4')));
            $clip = BuilderAsset::factory()->video()->create(['organization_id' => $organization->id, 'path' => $path, 'thumbnail_path' => null]);
            $document['stage']['background']['layers'] = [['id' => 'bg_video', 'type' => 'video', 'assetId' => $clip->id, 'fit' => 'cover', 'visible' => true, 'opacity' => 1]];
        }
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

    /**
     * @param  list<string>  $states
     * @return list<string>
     */
    private function withoutRepeats(array $states): array
    {
        $out = [];
        foreach ($states as $state) {
            if ($out === [] || end($out) !== $state) {
                $out[] = $state;
            }
        }

        return $out;
    }
}
