<?php

namespace Tests\Browser;

use App\Models\BuilderAd;
use App\Models\Campaign;
use App\Models\Media;
use App\Models\Organization;
use App\Models\PlaylistItem;
use App\Models\Screen;
use App\Services\AdPublisher;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Storage;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The advertising break, on an actual television.
 *
 * This is the part that has to behave on an organization's wall for weeks without anybody
 * watching it, so it is tested for more than "the advert appeared":
 *
 *   · a break comes BETWEEN two items, never in the middle of one (owner, 2026-10-23: "jese hi ad
 *     khatam ho toh hamari network ads chale"): a picture, a video or an Ad Builder page on screen
 *     when it comes due plays to its end, and the next item comes on after the adverts
 *   · only one video decodes at a time, so a cheap box is never asked to play two
 *   · the advert's element is DESTROYED when the break ends. A hidden <video> keeps
 *     its decoder and its buffer, and a set left running would collect one an hour
 *
 * config/signage.php turns the interval right down in the dusk environment, so a
 * whole break happens in seconds instead of an hour.
 */
class NetworkAdBreakTest extends DuskTestCase
{
    use DatabaseMigrations;

    /**
     * An organization that agreed, a television cleared for advertising, and one two-second campaign.
     *
     * @return array{0: Organization, 1: Screen}
     */
    private function setUpScreen(string $token): array
    {
        $organization = Organization::factory()->create(['name' => 'Alpha Mart', 'accepts_network_ads' => true]);
        $screen = Screen::factory()->withToken($token)->create([
            'organization_id' => $organization->id, 'name' => 'Counter TV', 'accepts_network_ads' => true,
        ]);

        $campaign = Campaign::factory()->lasting(10)->create([
            'name' => 'Coca-Cola', 'path' => $this->putImage('campaigns/advert.png', 220, 30, 40),
            'thumbnail_path' => null, 'mime_type' => 'image/png',
        ]);
        $campaign->screens()->attach($screen);

        return [$organization, $screen];
    }

    /**
     * A break that comes due while a picture is on screen waits for it to end, and the adverts play between it and
     * the next picture: the one under them had its whole ten seconds, and the next comes on after them. The advert
     * sits over the content rather than replacing it, its element is gone afterwards, not merely hidden — and the
     * break comes round again.
     */
    public function test_a_break_waits_for_the_picture_on_screen_and_plays_before_the_next_one(): void
    {
        [$organization, $screen] = $this->setUpScreen('ad-token');

        foreach (['Menu' => [20, 90, 200], 'Specials' => [200, 140, 20]] as $title => [$r, $g, $b]) {
            $picture = Media::factory()->create([
                'organization_id' => $organization->id, 'title' => $title,
                'type' => Media::TYPE_IMAGE, 'mime_type' => 'image/png',
                'path' => $this->putImage("media/{$organization->id}/".strtolower($title).'.png', $r, $g, $b),
                'thumbnail_path' => null,
            ]);
            // Ten seconds each, longer than the dusk environment's six between breaks: every break comes due mid-picture.
            PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $picture->id, 'position' => $picture->id, 'duration_seconds' => 10]);
        }

        $this->browse(function (Browser $tv) {
            $tv->visit('/login');
            $tv->script("localStorage.clear(); localStorage.setItem('signage.device.token', 'ad-token');");
            $tv->visit('/player');
            $this->watchTheWall($tv);

            // -- The organization's own content ----------------------------------------
            $tv->waitUsing(30, 200, fn () => $tv->script(
                'return !!document.querySelector("#layer-a:not([hidden]) img, #layer-b:not([hidden]) img");'
            )[0]);

            // -- The break: the first over a picture the watch saw come on --------
            $tv->waitUsing(60, 100, fn () => $tv->script(
                'return window.__wall.breaks.length > 0 && !document.getElementById("layer-ad").hidden;'
            )[0]);

            // The advert really is on screen, and the content is still underneath it
            // rather than having been torn down.
            $this->assertSame(1, $tv->script('return document.getElementById("layer-ad").children.length;')[0]);
            $this->assertTrue($tv->script(
                'return !!document.querySelector("#layer-a:not([hidden]) img, #layer-b:not([hidden]) img");'
            )[0], 'the organization content should still be in the DOM, merely covered');

            // The picture under it had its whole ten seconds: the break waited for it to end. They count from when the
            // player asked for it and the watch sees it from when it had loaded, half a second later; a break that cut
            // it, as the player before 2026-10-23 did, came two to six seconds in.
            $break = $tv->script('return window.__wall.breaks[0];')[0];
            $this->assertGreaterThanOrEqual(8500, $break['shownFor'], "the break cut the picture after {$break['shownFor']} ms of its 10 s");

            // -- And afterwards --------------------------------------------------
            $tv->waitUsing(30, 200, fn () => $tv->script(
                'return document.getElementById("layer-ad").hidden;'
            )[0]);

            // Emptied, not just hidden. This is the whole difference between a set
            // that runs for a month and one that slows to a crawl.
            $this->assertSame(0, $tv->script('return document.getElementById("layer-ad").children.length;')[0],
                'the advert element should be destroyed when the break ends');

            // The organization's content is back on screen — the NEXT picture, from its start, not the one before.
            $tv->waitUsing(5, 100, fn () => $tv->script(
                'const img = document.querySelector("#layer-a:not([hidden]) img, #layer-b:not([hidden]) img"); '
                .'return !!img && img.getAttribute("src") !== window.__wall.breaks[0].under;'
            )[0], 'the picture before the break came back instead of the next one');

            // -- And it happens AGAIN ---------------------------------------------
            // The countdown has to restart when a break ends. A break that fires once
            // and never again would look perfectly fine in a short test and sell the
            // brand one impression a day.
            $tv->waitUsing(30, 200, fn () => $tv->script(
                'return !document.getElementById("layer-ad").hidden;'
            )[0]);

            $this->assertSame(1, $tv->script('return document.getElementById("layer-ad").children.length;')[0]);

            $tv->visit('/login');
            $tv->script('localStorage.clear();');
        });
    }

    /**
     * A video on screen when a break comes due plays to its own end first — never paused or cut in the middle — and
     * the adverts come after it; the video starts again from its beginning once they are over, and never two
     * videos decode at once.
     */
    public function test_a_video_plays_to_its_end_before_the_break(): void
    {
        [$organization, $screen] = $this->setUpScreen('video-ad-token');

        $this->browse(function (Browser $tv) use ($organization, $screen) {
            // -- Record a real clip, long enough to still be playing at the break --
            $tv->visit('/login');
            $tv->script(<<<'JS'
                window.__clip = false;
                (async () => {
                    const types = ['video/mp4;codecs=avc1.42E01E', 'video/mp4'];
                    const mimeType = types.find((t) => MediaRecorder.isTypeSupported(t));
                    if (!mimeType) { window.__clip = 'no-mp4-encoder'; return; }

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
                    recorder.onstop = () => {
                        clearInterval(draw);
                        const reader = new FileReader();
                        reader.onload = () => { window.__clip = reader.result; };
                        reader.readAsDataURL(new Blob(chunks, { type: 'video/mp4' }));
                    };
                    recorder.start();
                    setTimeout(() => recorder.stop(), 16000);
                })();
            JS);

            $tv->waitUsing(60, 500, fn () => $tv->script('return window.__clip;')[0] !== false);
            $clip = $tv->script('return window.__clip;')[0];

            if ($clip === 'no-mp4-encoder') {
                $this->markTestSkipped('This Chrome build cannot record MP4, so there is no real video to interrupt.');
            }

            $binary = base64_decode(explode(',', $clip, 2)[1], true);
            $path = "media/{$organization->id}/clip.mp4";
            Storage::disk('public')->put($path, $binary);

            $video = Media::factory()->create([
                'organization_id' => $organization->id, 'title' => 'Long clip',
                'type' => Media::TYPE_VIDEO, 'mime_type' => 'video/mp4',
                'path' => $path, 'thumbnail_path' => null, 'duration_seconds' => 16,
            ]);

            PlaylistItem::create([
                'screen_id' => $screen->id, 'media_id' => $video->id,
                'position' => 0, 'duration_seconds' => 16,
            ]);

            // -- Play it ---------------------------------------------------------
            $tv->script("localStorage.clear(); localStorage.setItem('signage.device.token', 'video-ad-token');");
            $tv->visit('/player');

            $onScreen = '#layer-a:not([hidden]) video, #layer-b:not([hidden]) video';

            $tv->waitUsing(30, 200, fn () => $tv->script("return !!document.querySelector('{$onScreen}');")[0]);

            // Every pause of a video before its end is written down: a break that cut the clip would show here.
            $tv->script(<<<'JS'
                window.__cut = [];
                document.addEventListener('pause', (event) => {
                    const video = event.target;
                    if (video.closest('#layer-a, #layer-b') && ! video.ended && video.currentTime < video.duration - 0.3) {
                        window.__cut.push(video.currentTime);
                    }
                }, true);
            JS);

            // -- The break: due six seconds in, it waits for the clip's end -------
            $tv->waitUsing(60, 100, fn () => $tv->script('return !document.getElementById("layer-ad").hidden;')[0]);

            $under = $tv->script("const v = document.querySelector('{$onScreen}'); return v ? [v.ended, v.currentTime, v.duration] : null;")[0];
            $this->assertNotNull($under, 'the clip should still be under the advert');
            $this->assertTrue($under[0] || $under[1] >= $under[2] - 0.3, "the break came before the clip's end ({$under[1]} of {$under[2]} s)");
            $this->assertSame([], $tv->script('return window.__cut;')[0], 'the clip was paused in the middle');

            // Nothing is decoding two videos at once: the advert here is an image, and the clip has ended.
            $this->assertSame(0, $tv->script(
                'return [...document.querySelectorAll("video")].filter((v) => !v.paused).length;'
            )[0]);

            // -- And after: the clip again, from its start, in an element of its own --
            $tv->script("document.querySelector('{$onScreen}').dataset.before = 'yes';");
            $tv->waitUsing(30, 200, fn () => $tv->script('return document.getElementById("layer-ad").hidden;')[0]);
            $tv->waitUsing(10, 200, fn () => $tv->script(
                "const v = document.querySelector('{$onScreen}'); return !!v && v.dataset.before !== 'yes' && v.paused === false;"
            )[0], 'the next item did not come on after the break');
            $this->assertLessThan(3, (float) $tv->script("return document.querySelector('{$onScreen}').currentTime;")[0]);

            // Nothing accumulates. Two content layers means at most two video elements
            // ever exist; the advert's own is destroyed with the break, so a set left
            // running for a month does not collect one decoder an hour.
            $this->assertSame(0, $tv->script('return document.getElementById("layer-ad").children.length;')[0]);
            $this->assertLessThanOrEqual(2, $tv->script('return document.querySelectorAll("video").length;')[0],
                'video elements are accumulating — one of the layers is not being cleared');

            $tv->visit('/login');
            $tv->script('localStorage.clear();');
        });
    }

    /**
     * An Ad Builder page under a break is let go while the advert plays — its runtime cannot be paused from the
     * player, and running on under the break it came back past its exit, its video still decoding (the brute-force
     * round, 2026-09-29) — and comes back from the start in a fresh frame when the break is over.
     */
    public function test_an_ad_page_under_a_break_is_let_go_and_comes_back_from_the_start(): void
    {
        [$organization, $screen] = $this->setUpScreen('page-break-token');

        $document = BuilderAd::blankDocument();
        $document['duration'] = 10;    // past the dusk environment's six seconds between breaks: the break comes due in it
        $document['elements'] = [[
            'id' => 'el_text', 'type' => 'text', 'name' => 'Headline', 'text' => 'Back from the break',
            'x' => 160, 'y' => 400, 'w' => 1600, 'h' => 240, 'rotation' => 0, 'opacity' => 1, 'z' => 0, 'locked' => false, 'visible' => true,
            'style' => ['fontSize' => 120, 'color' => '#ffffff'], 'animations' => ['in' => ['effect' => 'fade', 'duration' => 0.5]],
        ]];
        $ad = BuilderAd::create([
            'organization_id' => $organization->id, 'name' => 'Back from the break', 'orientation' => BuilderAd::LANDSCAPE,
            'document' => $document,
        ]);
        $page = app(AdPublisher::class)->publish($ad);
        PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $page->id, 'position' => 0, 'duration_seconds' => null]);

        $this->browse(function (Browser $tv) use ($ad) {
            $tv->visit('/login');
            $tv->script("localStorage.clear(); localStorage.setItem('signage.device.token', 'page-break-token');");
            $tv->visit('/player');

            $frame = 'document.querySelector("#layer-a:not([hidden]) iframe, #layer-b:not([hidden]) iframe")';
            $isThePage = "(({$frame} || {}).dataset || {}).src && {$frame}.dataset.src.includes('/ads/{$ad->storage_key}/')";

            // The page comes on, and its frame is noted.
            $tv->waitUsing(30, 200, fn () => (bool) $tv->script("return {$isThePage};")[0]);
            $tv->script("window.__first = {$frame}; window.__firstAt = Date.now();");

            // The break comes after the page's ten seconds, never in them — and the page is let go under it, hidden.
            $tv->waitUsing(30, 100, fn () => $tv->script('return !document.getElementById("layer-ad").hidden;')[0]);
            $this->assertGreaterThanOrEqual(9000, $tv->script('return Date.now() - window.__firstAt;')[0], 'the break cut the page short');
            $tv->waitUsing(5, 100, fn () => $tv->script('return window.__first.getAttribute("src") === "about:blank";')[0]);
            $this->assertSame('hidden', $tv->script('return window.__first.style.visibility;')[0]);

            // After it: the page again, from the start, in a frame of its own — shown.
            $tv->waitUsing(30, 200, fn () => $tv->script('return document.getElementById("layer-ad").hidden;')[0]);
            $tv->waitUsing(15, 200, fn () => (bool) $tv->script(
                "const f = {$frame}; return !!f && f !== window.__first && {$isThePage} && f.style.visibility !== 'hidden';"
            )[0], 'the page did not come back in a fresh frame');

            $tv->visit('/login');
            $tv->script('localStorage.clear();');
        });
    }

    /** Notes, from now on, when each picture came on and when each break began: over which picture, shown how long. */
    private function watchTheWall(Browser $tv): void
    {
        $tv->script(<<<'JS'
            window.__wall = { shown: null, breaks: [] };
            const picture = () => document.querySelector('#layer-a:not([hidden]) img, #layer-b:not([hidden]) img');
            // A picture already up when the watch began has no known start: a break over it is not measured.
            const note = (first) => {
                const img = picture();
                const src = img ? img.getAttribute('src') : null;
                if (src && (! window.__wall.shown || window.__wall.shown.src !== src)) window.__wall.shown = { src, at: first === true ? null : Date.now() };
            };
            ['layer-a', 'layer-b'].forEach((id) => new MutationObserver(note)
                .observe(document.getElementById(id), { attributes: true, attributeFilter: ['hidden'], childList: true }));
            const ad = document.getElementById('layer-ad');
            new MutationObserver(() => {
                if (! ad.hidden && window.__wall.shown && window.__wall.shown.at !== null && window.__wall.breaks.length === 0) {
                    window.__wall.breaks.push({ under: window.__wall.shown.src, shownFor: Date.now() - window.__wall.shown.at });
                }
            }).observe(ad, { attributes: true, attributeFilter: ['hidden'] });
            note(true);
        JS);
    }

    /**
     * A television that carries no advertising is never interrupted.
     *
     * The consent switches are the whole basis of the deal with each organization, so this is
     * checked where it actually matters — on the set itself, not only in the API.
     */
    public function test_a_screen_that_carries_no_advertising_is_never_interrupted(): void
    {
        $organization = Organization::factory()->create(['accepts_network_ads' => true]);
        // The organization agreed; this particular television did not.
        $screen = Screen::factory()->withToken('clean-token')->create([
            'organization_id' => $organization->id, 'accepts_network_ads' => false,
        ]);

        $campaign = Campaign::factory()->lasting(10)->create([
            'path' => $this->putImage('campaigns/advert.png', 220, 30, 40), 'thumbnail_path' => null,
        ]);
        $campaign->screens()->attach($screen);

        $poster = Media::factory()->create([
            'organization_id' => $organization->id, 'type' => Media::TYPE_IMAGE, 'mime_type' => 'image/png',
            'path' => $this->putImage("media/{$organization->id}/menu.png", 20, 90, 200), 'thumbnail_path' => null,
        ]);
        PlaylistItem::create([
            'screen_id' => $screen->id, 'media_id' => $poster->id, 'position' => 0, 'duration_seconds' => 120,
        ]);

        $this->browse(function (Browser $tv) {
            $tv->visit('/login');
            $tv->script("localStorage.clear(); localStorage.setItem('signage.device.token', 'clean-token');");
            $tv->visit('/player');

            // Watch the advert layer from here on rather than looking at it once: a break
            // lasts ten seconds, and a single look after a fixed wait can fall either side
            // of one. Building an advert counts as much as showing it — a screen that
            // carries none should never be handed one at all.
            $tv->script(<<<'JS'
                window.__advertAppeared = false;
                const layer = document.getElementById('layer-ad');
                new MutationObserver(() => {
                    if (! layer.hidden || layer.childElementCount > 0) window.__advertAppeared = true;
                }).observe(layer, { attributes: true, attributeFilter: ['hidden'], childList: true });
            JS);

            $tv->waitUsing(30, 200, fn () => $tv->script(
                'return !!document.querySelector("#layer-a:not([hidden]) img, #layer-b:not([hidden]) img");'
            )[0]);

            // Two whole break intervals and some (six seconds each in the dusk environment,
            // config/signage.php — fifteen seconds in all), watched the whole way, and cut
            // short the moment an advert turns up, which is then reported below.
            $watch = 2 * (int) config('signage.ad_break_seconds') + 3;
            $until = microtime(true) + $watch;
            $tv->waitUsing($watch + 10, 250,
                fn () => $tv->script('return window.__advertAppeared;')[0] === true || microtime(true) >= $until);

            $this->assertFalse($tv->script('return window.__advertAppeared;')[0],
                'a screen that carries no advertising was interrupted anyway');
            $this->assertSame(0, $tv->script('return document.getElementById("layer-ad").children.length;')[0]);

            // And not because the player had stopped: the organization's own picture is still up.
            $this->assertTrue($tv->script(
                'return !!document.querySelector("#layer-a:not([hidden]) img, #layer-b:not([hidden]) img");'
            )[0], 'the player stopped showing the organization\'s content while it was being watched');

            $tv->visit('/login');
            $tv->script('localStorage.clear();');
        });
    }
}
