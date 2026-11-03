<?php

namespace Tests\Browser;

use App\Models\BuilderAd;
use App\Models\BuilderAsset;
use App\Models\Campaign;
use App\Models\Channel;
use App\Models\ChannelAd;
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
 * Everything a screen can play, on one playlist, end to end (owner, 2026-11-03 — "Library, Ad Builder, Channel, Network
 * Ads: videos aur images ... ek hi playlist mein ... end-to-end deep testing"): the library's picture and video, an Ad
 * Builder page with a picture and one over a video, a channel holding a picture, a video and an Ad Builder page, and a
 * network break of a picture and a video, on a screen with Fade, every screen's own. Watched the whole way: what is on
 * the glass every frame and in every moment the page is left in, every media element and frame alive, and every error
 * the page throws. Then the same while the server changes the screen under it, the line drops, and files are broken.
 */
class EverythingOnOnePlaylistTest extends DuskTestCase
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

    public function test_every_kind_of_item_on_one_landscape_playlist_comes_on_in_turn_and_the_glass_is_never_empty(): void
    {
        $this->playsEverything('landscape', decode: null);
    }

    public function test_every_kind_of_item_on_one_portrait_playlist_on_a_set_that_decodes_its_videos(): void
    {
        // A turned Fire TV plays every video itself (decoded-video), a page's own videos too (§12.16): tried here with the
        // set's own word, as DecodedVideoTest does.
        $this->playsEverything('portrait', decode: '1');
    }

    public function test_the_screen_changed_under_it_the_line_cut_and_back_never_leave_the_glass_empty(): void
    {
        $world = $this->world('landscape');

        $this->browse(function (Browser $tv) use ($world) {
            $this->watch($tv);
            $this->startTheSet($tv, null);
            $tv->waitUntil("window.__all.seen.some((s) => s.includes('library-picture'))", 30, 'the screen never started');

            // The playlist changes while it plays: the library's picture goes, a new picture comes, and the screen is
            // cut over to None. The player asks at once when the line comes back (the online event).
            $fresh = $this->picture($world['organization'], 'new-picture', [250, 140, 0]);
            PlaylistItem::where('media_id', $world['picture']->id)->delete();
            PlaylistItem::create(['screen_id' => $world['screen']->id, 'media_id' => $fresh->id, 'position' => 9, 'duration_seconds' => 10]);
            $world['screen']->update(['transition' => 'none']);
            $tv->script("window.dispatchEvent(new Event('online'));");
            $tv->waitUntil("window.__all.seen.some((s) => s.includes('new-picture'))", 120, 'the changed playlist never reached the screen');
            $this->assertNotContains('library-picture.png', $this->namesAfter($tv, 'new-picture'), 'a line taken off kept coming on');

            // Turned to portrait while it plays: the stage turns, and nothing goes dark on the way.
            $world['screen']->update(['orientation' => 'portrait']);
            $tv->script("window.dispatchEvent(new Event('online'));");
            $tv->waitUntil("document.getElementById('stage').dataset.orientation === 'portrait'", 40, 'the stage never turned');

            // The line drops: the screen plays on from the worker's copies (§15), every kind still coming on.
            $tv->waitUntil('!!navigator.serviceWorker.controller', 20, 'no worker kept the set');
            $marker = $tv->script('return window.__all.seen.length;')[0];
            $this->network($tv, offline: true);
            $tv->waitUntil("window.__all.seen.slice({$marker}).some((s) => s.includes('library-video')) && window.__all.seen.slice({$marker}).some((s) => s.includes('new-picture'))", 120, 'the screen stopped playing with no line');
            $this->network($tv);

            $this->assertGlassNeverEmpty($tv);
            $this->assertNoErrors($tv);
        });
    }

    public function test_broken_files_among_every_kind_are_stepped_over_and_the_rest_plays_on(): void
    {
        // A picture whose file is gone, a "video" that is no video, and an Ad Builder page whose published file is gone:
        // each is stepped over, the item before stays up meanwhile, and everything else comes round again.
        $world = $this->world('landscape');
        $gone = Media::factory()->create([
            'organization_id' => $world['organization']->id, 'title' => 'gone', 'type' => Media::TYPE_IMAGE, 'mime_type' => 'image/png',
            'path' => "media/{$world['organization']->id}/gone.png", 'thumbnail_path' => null,
        ]);
        $garbage = "media/{$world['organization']->id}/garbage.mp4";
        Storage::disk('public')->put($garbage, str_repeat("not a video\n", 4000));
        $notAVideo = Media::factory()->video()->create([
            'organization_id' => $world['organization']->id, 'title' => 'garbage', 'path' => $garbage, 'thumbnail_path' => null, 'duration_seconds' => 4,
        ]);
        $pageGone = $this->page($world['organization'], 'Page gone', 'landscape', picture: null, video: null);
        Storage::disk('public')->delete($pageGone->path);
        foreach ([$gone, $notAVideo, $pageGone] as $i => $media) {
            PlaylistItem::create(['screen_id' => $world['screen']->id, 'media_id' => $media->id, 'position' => 20 + $i, 'duration_seconds' => $media->type === Media::TYPE_IMAGE ? 10 : null]);
        }
        Campaign::query()->update(['is_active' => false]);

        $this->browse(function (Browser $tv) {
            $this->watch($tv);
            $this->startTheSet($tv, null);

            // Round twice: the first item after the broken three is the playlist's first again.
            $tv->waitUntil("window.__all.seen.filter((s) => s.includes('library-picture')).length >= 2", 150, 'the playlist never came round past its broken files');

            $this->assertGlassNeverEmpty($tv);
            $seen = implode(' ', $tv->script('return window.__all.seen;')[0]);
            $this->assertStringNotContainsString('gone.png', $seen, 'a picture with no file came on');
            $this->assertStringNotContainsString('garbage.mp4', $seen, 'a file that is no video came on');
        });
    }

    /**
     * One playlist with every kind on a screen of this orientation: all of it comes on in turn with the break between
     * its items, the glass is never empty, the page's own video is decoded on a set that decodes, nothing piles up and
     * nothing throws.
     */
    private function playsEverything(string $orientation, ?string $decode): void
    {
        $world = $this->world($orientation);

        $this->browse(function (Browser $tv) use ($decode, $orientation) {
            $this->watch($tv);
            $this->startTheSet($tv, $decode);

            $every = ['library-picture', 'library-video', 'page:Page with a picture', 'channel-picture', 'channel-video', 'page:Channel page', 'page:Page over a video', 'ad campaigns/network-picture', 'ad campaigns/network-video'];
            $tv->waitUntil('('.json_encode($every).').every((name) => window.__all.seen.some((s) => s.includes(name)))', 300,
                'not every kind came on: '.json_encode($tv->script('return window.__all ? [...new Set(window.__all.seen)] : null;')[0]));

            $this->assertGlassNeverEmpty($tv);
            $this->assertNoErrors($tv);

            // Nothing piles up: at most the item on screen, the one made ready, the one fading out and an advert hold a
            // player of their own; one frame for the page on screen and one for the page made ready; the advert layer
            // empty whenever it is down.
            $most = $tv->script('return window.__all.most;')[0];
            $this->assertLessThanOrEqual(4, $most['players'], 'media players piled up: '.json_encode($most));
            $this->assertLessThanOrEqual(2, $most['frames'], 'frames piled up: '.json_encode($most));
            $this->assertSame(0, $most['adLeftovers'], 'the advert layer kept an advert while it was down');

            // Fade, every screen's own: switches were dissolves, two layers part-way.
            $this->assertGreaterThan(0, $tv->script('return window.__all.fading;')[0], 'no switch faded');

            if ($decode === '1') {
                // The page's own video was the player's decoder inside the page, and every video on the stage too.
                $this->assertTrue($tv->script('return window.__all.decodedInAPage;')[0], 'the page\'s video was not decoded');
                $this->assertSame(0, $tv->script('return window.__all.plainVideos;')[0], 'a <video> played on the turned screen');
            }

            $this->assertSame($orientation, $tv->script("return document.getElementById('stage').dataset.orientation;")[0]);
        });
    }

    /**
     * Before the page loads: what is on the glass every frame and in every moment the page is left in (as
     * EverySwitchIsASwapTest reads it), the most media players, frames and fading layers alive at once, whether an advert
     * stayed in its layer while the layer was down, and every error the page threw.
     */
    private function watch(Browser $tv): void
    {
        $tools = new ChromeDevToolsDriver($tv->driver);
        $this->pageScripts[] = [$tools, (string) $tools->execute('Page.addScriptToEvaluateOnNewDocument', ['source' => <<<'JS'
            (() => {
                if (window.top !== window) return;
                const all = window.__all = { seen: [], errors: [], most: { players: 0, frames: 0, adLeftovers: 0 }, fading: 0, decodedInAPage: false, plainVideos: 0 };
                window.addEventListener('error', (e) => all.errors.push(String(e.message || e)));
                window.addEventListener('unhandledrejection', (e) => all.errors.push('rejection: ' + String(e.reason && e.reason.message || e.reason)));
                const nameOf = (n) => {
                    const src = (n.dataset && (n.dataset.src || n.dataset.item)) || n.getAttribute('src') || n.tagName;
                    return src.split('?')[0].split('/').slice(-2).join('/');
                };
                const ready = (n) => n.tagName === 'IMG' ? n.complete && n.naturalWidth > 0
                    : n.tagName === 'IFRAME' ? !!n.getAttribute('src') : n.readyState >= 2;
                const pageOf = (n) => { try { return n.contentDocument; } catch (e) { return null; } };
                const pageName = (n) => {
                    const page = pageOf(n);
                    const words = page && page.body ? page.body.innerText.trim().split('\n')[0] : '';
                    return 'page:' + (words || nameOf(n));
                };
                const glass = () => {
                    const ad = document.getElementById('layer-ad');
                    if (ad && !ad.hidden) {
                        const up = [...ad.children].find((c) => !c.hidden);
                        return up && ready(up) ? 'ad ' + nameOf(up) : 'NOTHING';
                    }
                    const fronts = [...document.querySelectorAll('#layer-a:not([hidden]) > *, #layer-b:not([hidden]) > *')];
                    if (!fronts.length) return 'NOTHING';
                    // While one fades in over another, either is on the glass: the one coming in names it.
                    const up = fronts.find((n) => n.parentElement.style.zIndex === '1') || fronts[0];
                    if (!ready(up)) return 'NOTHING';
                    return up.tagName === 'IFRAME' ? pageName(up) : nameOf(up);
                };
                const note = () => {
                    const now = glass();
                    if (all.seen[all.seen.length - 1] !== now) all.seen.push(now);
                };
                const watch = () => {
                    for (const id of ['layer-a', 'layer-b', 'layer-ad']) {
                        const layer = document.getElementById(id);
                        if (!layer || layer.__all) continue;
                        layer.__all = true;
                        new MutationObserver(note).observe(layer, { attributes: true, attributeFilter: ['hidden', 'style'], childList: true, subtree: true });
                    }
                    const pages = [...document.querySelectorAll('iframe')];
                    const inPages = pages.map(pageOf).filter(Boolean);
                    const players = document.querySelectorAll('video, decoded-video').length
                        + inPages.reduce((n, d) => n + d.querySelectorAll('video[src], decoded-video').length, 0);
                    all.most.players = Math.max(all.most.players, players);
                    all.most.frames = Math.max(all.most.frames, pages.length);
                    const ad = document.getElementById('layer-ad');
                    if (ad && ad.hidden && ad.children.length && !ad.querySelector(':scope > [hidden]')) all.most.adLeftovers = Math.max(all.most.adLeftovers, ad.children.length);
                    const shown = [...document.querySelectorAll('#layer-a:not([hidden]), #layer-b:not([hidden]), #layer-ad:not([hidden])')];
                    if (shown.some((l) => { const o = Number(getComputedStyle(l).opacity); return o > 0.05 && o < 0.95; })) all.fading++;
                    if (inPages.some((d) => d.querySelector('decoded-video'))) all.decodedInAPage = true;
                    all.plainVideos = Math.max(all.plainVideos, [...document.querySelectorAll('.media-layer video')].filter((v) => !v.paused).length);
                    note();
                    requestAnimationFrame(watch);
                };
                document.addEventListener('DOMContentLoaded', watch);
            })();
        JS])['identifier']];
    }

    private function startTheSet(Browser $tv, ?string $decode): void
    {
        $tv->resize(1920, 1080);
        $tv->visit('/login');
        $tv->script("localStorage.clear(); localStorage.setItem('signage.device.token', 'everything-token');");
        if ($decode !== null) {
            $tv->script("localStorage.setItem('signage.decode_turned_video', '{$decode}');");
        }
        $tv->visit('/player');
        // A page is the player's own origin only where the worker keeps the set (§15): then it can be looked into, and
        // its videos decoded.
        $tv->waitUntil('!!navigator.serviceWorker.controller', 20, 'no worker took the page over');
        $tv->refresh();
    }

    private function assertGlassNeverEmpty(Browser $tv): void
    {
        $seen = $tv->script('return window.__all.seen;')[0];
        $first = array_search(true, array_map(fn (string $s) => $s !== 'NOTHING', $seen), true);
        $this->assertNotFalse($first, 'nothing ever came on');
        $this->assertNotContains('NOTHING', array_slice($seen, (int) $first), 'the glass was empty: '.json_encode(array_slice($seen, (int) $first)));
    }

    private function assertNoErrors(Browser $tv): void
    {
        $this->assertSame([], $tv->script('return window.__all.errors;')[0], 'the player threw');
    }

    /** @return list<string> the files seen since `$name` came on */
    private function namesAfter(Browser $tv, string $name): array
    {
        $seen = $tv->script('return window.__all.seen;')[0];
        $from = array_search(true, array_map(fn (string $s) => str_contains($s, $name), $seen), true);

        return array_map(fn (string $s) => basename($s), array_slice($seen, (int) $from));
    }

    /**
     * The world on one screen: the library's picture and video; an Ad Builder page with a picture and one over a video;
     * the organization's own channel with a picture, a video and an Ad Builder page; a picture and a video advert.
     *
     * @return array{organization: Organization, screen: Screen, picture: Media}
     */
    private function world(string $orientation): array
    {
        $organization = Organization::factory()->create(['name' => 'Everything Mart', 'accepts_network_ads' => true]);
        $screen = Screen::factory()->withToken('everything-token')->create([
            'organization_id' => $organization->id, 'name' => 'Everything TV', 'orientation' => $orientation, 'accepts_network_ads' => true,
        ]);
        $shape = str_starts_with($orientation, 'portrait') ? 'portrait' : 'landscape';

        $picture = $this->picture($organization, 'library-picture', [200, 40, 40]);
        $video = $this->video($organization, 'library-video', 'portrait-red-top.mp4');
        $pageWithAPicture = $this->page($organization, 'Page with a picture', $shape, picture: $this->asset($organization, 'page-picture.png', [40, 160, 60]), video: null);
        $pageOverAVideo = $this->page($organization, 'Page over a video', $shape, picture: null, video: $this->asset($organization, 'portrait-red-top-2s.mp4', null));

        $channel = Channel::create(['organization_id' => $organization->id, 'name' => 'Everything channel', 'orientation' => $shape, 'is_active' => true]);
        $channelPage = $this->page($organization, 'Channel page', $shape, picture: $this->asset($organization, 'channel-page-picture.png', [60, 60, 200]), video: null);
        foreach ([
            [$this->picture($organization, 'channel-picture', [230, 200, 0]), 10],
            [$this->video($organization, 'channel-video', 'portrait-red-top-2s.mp4'), null],
            [$channelPage, null],
        ] as $position => [$media, $seconds]) {
            ChannelAd::create(['channel_id' => $channel->id, 'media_id' => $media->id, 'title' => $media->title, 'duration_seconds' => $seconds, 'position' => $position]);
        }

        Campaign::factory()->lasting(10)->create([
            'name' => 'network-picture', 'path' => $this->putImage('campaigns/network-picture.png', 255, 105, 180), 'thumbnail_path' => null, 'mime_type' => 'image/png',
        ])->screens()->attach($screen);
        $advert = 'campaigns/network-video.mp4';
        Storage::disk('public')->put($advert, file_get_contents(base_path(self::FIXTURES.'portrait-red-top-2s.mp4')));
        Campaign::factory()->video(2)->create(['name' => 'network-video', 'path' => $advert, 'thumbnail_path' => null])->screens()->attach($screen);

        foreach ([[$picture, 10], [$video, null], [$pageWithAPicture, null], [$channel, null], [$pageOverAVideo, null]] as $position => [$line, $seconds]) {
            PlaylistItem::create([
                'screen_id' => $screen->id, 'position' => $position, 'duration_seconds' => $seconds,
                'media_id' => $line instanceof Media ? $line->id : null, 'channel_id' => $line instanceof Channel ? $line->id : null,
            ]);
        }

        return ['organization' => $organization, 'screen' => $screen, 'picture' => $picture];
    }

    private function picture(Organization $organization, string $name, array $rgb): Media
    {
        return Media::factory()->create([
            'organization_id' => $organization->id, 'title' => $name, 'type' => Media::TYPE_IMAGE, 'mime_type' => 'image/png',
            'path' => $this->putImage("media/{$organization->id}/{$name}.png", ...$rgb), 'thumbnail_path' => null,
        ]);
    }

    private function video(Organization $organization, string $name, string $fixture): Media
    {
        $path = "media/{$organization->id}/{$name}.mp4";
        Storage::disk('public')->put($path, file_get_contents(base_path(self::FIXTURES.$fixture)));

        return Media::factory()->video()->create([
            'organization_id' => $organization->id, 'title' => $name, 'path' => $path, 'thumbnail_path' => null,
            'size' => filesize(base_path(self::FIXTURES.$fixture)), 'width' => 360, 'height' => 640, 'orientation' => 'portrait',
            'duration_seconds' => str_contains($fixture, '2s') ? 2 : 4,
        ]);
    }

    /** An Ad Builder asset: a picture of one colour, or (rgb null) a video fixture. */
    private function asset(Organization $organization, string $file, ?array $rgb): BuilderAsset
    {
        $path = "builder/{$organization->id}/assets/".Str::ulid().'-'.$file;

        if ($rgb === null) {
            Storage::disk('public')->put($path, file_get_contents(base_path(self::FIXTURES.$file)));

            return BuilderAsset::factory()->video()->create(['organization_id' => $organization->id, 'path' => $path, 'thumbnail_path' => null]);
        }

        $this->putImage($path, ...$rgb);

        return BuilderAsset::factory()->create(['organization_id' => $organization->id, 'path' => $path, 'thumbnail_path' => null, 'mime_type' => 'image/png']);
    }

    /** A published ten-second page named by its words, with a picture on its stage or over a background video. */
    private function page(Organization $organization, string $words, string $shape, ?BuilderAsset $picture, ?BuilderAsset $video): Media
    {
        $document = BuilderAd::blankDocument($shape);
        $document['duration'] = 10;
        $document['elements'] = [[
            'id' => 'el_words', 'type' => 'text', 'name' => 'Words', 'text' => $words,
            'x' => 60, 'y' => 60, 'w' => 900, 'h' => 160, 'rotation' => 0, 'opacity' => 1, 'z' => 2, 'locked' => false, 'visible' => true,
            'style' => ['fontSize' => 80, 'color' => '#ffffff'], 'animations' => ['in' => ['effect' => 'fade', 'duration' => 0.5]],
        ]];
        if ($picture !== null) {
            $document['elements'][] = [
                'id' => 'el_picture', 'type' => 'image', 'name' => 'Picture', 'assetId' => $picture->id,
                'x' => 100, 'y' => 300, 'w' => 600, 'h' => 340, 'rotation' => 0, 'opacity' => 1, 'z' => 1, 'locked' => false, 'visible' => true,
                'style' => ['fit' => 'cover'], 'animations' => [],
            ];
        }
        if ($video !== null) {
            $document['stage']['background']['layers'] = [['id' => 'bg_video', 'type' => 'video', 'assetId' => $video->id, 'fit' => 'cover', 'visible' => true, 'opacity' => 1]];
        }
        $ad = BuilderAd::create(['organization_id' => $organization->id, 'name' => $words, 'orientation' => $shape, 'document' => $document]);

        return app(AdPublisher::class)->publish($ad);
    }
}
