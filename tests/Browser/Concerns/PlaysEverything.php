<?php

namespace Tests\Browser\Concerns;

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
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;

/**
 * Everything a screen can play, on one screen, and a watch on what is on its glass: the library's picture and video, an
 * Ad Builder page with a picture and one over a video, a channel holding a picture, a video and an Ad Builder page, and
 * a network break of a picture and a video (EverythingOnOnePlaylistTest, ExitQuestionEverywhereTest). A class using it
 * calls forgetPageScripts() in its tearDown.
 */
trait PlaysEverything
{
    private const FIXTURES = 'tests/Browser/fixtures/video/';

    /** @var list<array{0: ChromeDevToolsDriver, 1: string}> */
    private array $pageScripts = [];

    /** Takes the watch out of the browser, which outlives the test. */
    private function forgetPageScripts(): void
    {
        foreach ($this->pageScripts as [$tools, $identifier]) {
            try {
                $tools->execute('Page.removeScriptToEvaluateOnNewDocument', ['identifier' => $identifier]);
            } catch (\Throwable) {
                // The browser is gone already, and its scripts with it.
            }
        }
        $this->pageScripts = [];
    }

    /**
     * Before the page loads: what is on the glass every frame and in every moment the page is left in (as
     * EverySwitchIsASwapTest reads it, and `window.__all.glass()` says at any moment), the most media players, frames and
     * fading layers alive at once, whether an advert stayed in its layer while the layer was down, and every error the
     * page threw.
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
                all.glass = glass;
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
