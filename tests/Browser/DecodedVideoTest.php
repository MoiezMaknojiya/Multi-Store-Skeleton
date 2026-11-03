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
use Facebook\WebDriver\Exception\TimeoutException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Storage;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * A turned screen's video, decoded by the player itself (docs/TV-ORIENTATION-SPEC.md §11, §12).
 *
 * A Fire TV's WebView puts a <video> on a hardware plane that no CSS turn reaches, so on a screen mounted another way
 * the player plays its videos through <decoded-video> (resources/js/player/decoded-video.js): mp4box.js reads the
 * file, WebCodecs decodes it, and each frame is drawn on a canvas that turns with the stage like a picture. Chrome has
 * WebCodecs too, so the path is tried here with the set's own override (signage.decode_turned_video = '1'); on a real
 * Fire TV it is the server's switch. The fixtures are real H.264 files made with ffmpeg: red over blue, 360 x 640 —
 * but for the fragmented one (ftyp, moov, moof, mdat, moof, mdat, mfra), recorded by Chrome's own MediaRecorder as a
 * browser or OBS records: its index says it lasts 0 seconds.
 */
class DecodedVideoTest extends DuskTestCase
{
    use DatabaseMigrations;

    private const FIXTURES = 'tests/Browser/fixtures/video/';

    /** @var list<array{0: ChromeDevToolsDriver, 1: string}> the scripts this test put on every page */
    private array $pageScripts = [];

    /**
     * A script run before anything else in every page the browser opens, until this test ends: Dusk keeps the browser
     * from one test to the next, and a script left behind ran in the next test's pages too (a second decoder held
     * back on top of the first, 2026-11-02).
     */
    private function onEveryPage(Browser $tv, string $source): void
    {
        $tools = new ChromeDevToolsDriver($tv->driver);
        $this->pageScripts[] = [$tools, (string) $tools->execute('Page.addScriptToEvaluateOnNewDocument', ['source' => $source])['identifier']];
    }

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

    public function test_a_turned_screen_plays_its_video_through_the_players_own_decoder_turned_with_the_stage(): void
    {
        [$screen] = $this->screenPlaying('portrait', ['portrait-red-top.mp4']);

        $this->browse(function (Browser $tv) use ($screen) {
            $this->startTheSet($tv, $screen, '1');

            // The video on the stage is the player's own decoder, drawing frames, and no <video> plays.
            $this->waitForDecodedFrames($tv, 10);
            $this->assertSame(0, $tv->script("return [...document.querySelectorAll('video')].filter((v) => !v.paused).length;")[0]);

            [$duration, $before] = $tv->script("const v = document.querySelector('.media-layer:not([hidden]) decoded-video'); return [v.duration, v.drawnFrames];")[0];
            $this->assertEqualsWithDelta(4, $duration, 0.2, 'its length is the file\'s own');
            $tv->pause(1000);
            $after = $tv->script("return document.querySelector('.media-layer:not([hidden]) decoded-video').drawnFrames;")[0];
            $this->assertGreaterThan($before + 15, $after, 'it draws frames as it plays, about thirty a second');

            // Turned with the stage: Portrait (rotated 90°) puts the picture's top, red, on the right of the panel.
            [$left, $right] = $this->panelHalves($tv);
            $this->assertSame('blue', $left, 'the left half of the panel is the picture\'s bottom');
            $this->assertSame('red', $right, 'the right half of the panel is the picture\'s top');

            // Paused, it holds; played, it goes on from there, as a <video> does under an advert break.
            $held = $tv->script(<<<'JS'
                const v = document.querySelector('.media-layer:not([hidden]) decoded-video');
                v.pause();
                const at = v.currentTime;
                return new Promise((resolve) => setTimeout(() => {
                    const still = v.currentTime;
                    v.play();
                    setTimeout(() => resolve([at, still, v.currentTime, v.paused]), 400);
                }, 600));
            JS)[0];
            $this->assertEqualsWithDelta($held[0], $held[1], 0.01, 'paused, its time stands still');
            $this->assertGreaterThan($held[1], $held[2], 'played again, it carries on');
            $this->assertFalse($held[3]);
        });
    }

    public function test_a_decoded_video_holds_no_more_than_three_of_the_decoders_frames_at_once(): void
    {
        // The Fire TV Stick, 2026-10-29: a hardware decoder's frames come from a pool Chromium keeps at two images in a
        // WebView, and with six held it now and then had none: the frame then drawn was empty, a flash of green, and the
        // stick's log said "no buffers currently available in the reader queue". Every frame each decoder hands out is
        // counted here, and every one closed: two held or asked for at most, and one already inside the decoder may
        // still come out after the asking stops. The decoder before held ten. The pool is each decoder's own, and the
        // next video is made ready while one plays (two decoders then): each is counted by itself.
        [$screen] = $this->screenPlaying('portrait', ['portrait-red-top.mp4']);

        $this->browse(function (Browser $tv) use ($screen) {
            $this->onEveryPage($tv, <<<'JS'
                (() => {
                    if (typeof window.VideoDecoder !== 'function') return;
                    const held = window.__heldFrames = { now: {}, most: 0, out: 0 };
                    const close = VideoFrame.prototype.close;
                    VideoFrame.prototype.close = function () {
                        if (!this.__closed && this.__decoder) { this.__closed = true; held.now[this.__decoder] -= 1; }
                        return close.call(this);
                    };
                    const Original = window.VideoDecoder;
                    let decoders = 0;
                    window.VideoDecoder = function (init) {
                        const id = ++decoders;
                        held.now[id] = 0;
                        return new Original({
                            output: (frame) => {
                                frame.__decoder = id;
                                held.out += 1;
                                held.now[id] += 1;
                                held.most = Math.max(held.most, held.now[id]);
                                init.output(frame);
                            },
                            error: init.error,
                        });
                    };
                    window.VideoDecoder.isConfigSupported = (config) => Original.isConfigSupported(config);
                })();
            JS);

            $this->startTheSet($tv, $screen, '1');
            $this->waitForDecodedFrames($tv, 60);

            [$out, $most] = $tv->script('return [window.__heldFrames.out, window.__heldFrames.most];')[0];
            $this->assertGreaterThan(60, $out, 'the decoder handed out its frames');
            $this->assertLessThanOrEqual(3, $most, 'no more than three of its frames are ever held at once');
        });
    }

    public function test_a_fire_tv_decodes_its_first_video_after_a_start_with_the_servers_switch_alone(): void
    {
        // Roadstar 4, 2026-10-28: a menu video alone on a turned screen. The decoder's chunk was asked for once the
        // playlist had come, and the first video was made before it was in: a <video>, sideways for a whole loop
        // after every start.
        [$screen] = $this->screenPlaying('portrait', ['portrait-red-top.mp4']);

        $this->browse(function (Browser $tv) use ($screen) {
            $tools = new ChromeDevToolsDriver($tv->driver);
            $agent = $tv->script('return navigator.userAgent;')[0];
            $tools->execute('Emulation.setUserAgentOverride', ['userAgent' => preg_replace('/\(([^;)]+)/', '($1; AFTKA', $agent, 1)]);

            try {
                $this->startTheSet($tv, $screen, null);
                $this->assertTrue($tv->script('return /\bAFT/.test(navigator.userAgent);')[0], 'the set reads as a Fire TV');

                $tv->waitUntil("!!document.querySelector('.media-layer > video, .media-layer > decoded-video')", 20, 'no video was ever put on the stage');
                $this->assertSame(0, $tv->script("return document.querySelectorAll('.media-layer video').length;")[0], 'its first video is no <video>');
                $this->waitForDecodedFrames($tv, 10);
            } finally {
                $tools->execute('Emulation.setUserAgentOverride', ['userAgent' => $agent]);
            }
        });
    }

    public function test_the_decoder_reads_a_file_in_pieces_wherever_its_index_is_and_shows_a_turn_written_in_the_file(): void
    {
        [$screen, $urls] = $this->screenPlaying('portrait', [
            'portrait-red-top.mp4', 'portrait-red-top-index-at-end.mp4', 'lying-with-a-turn-flag.mp4', 'portrait-red-top-fragmented.mp4',
        ]);
        $broken = 'media/'.$screen->organization_id.'/broken.mp4';
        Storage::disk('public')->put($broken, str_repeat('not a video ', 400));
        $urls['broken'] = Storage::disk('public')->url($broken);

        $this->browse(function (Browser $tv) use ($screen, $urls) {
            $this->startTheSet($tv, $screen, '1');
            $tv->waitUntil("!!window.customElements.get('decoded-video')", 15, 'the decoder was never loaded');

            // The server here ignores ranges; the worker and Nginx do not. This page's fetch answers every range of a
            // video, so the decoder's piece-by-piece reading is what is tried.
            $tv->script(<<<'JS'
                const original = window.fetch.bind(window);
                const files = {};
                window.rangesAnswered = 0;
                window.fetch = async (url, init) => {
                    const range = init && init.headers && init.headers.Range;
                    if (!range || !/\.mp4(\?|$)/.test(String(url))) return original(url, init);
                    files[url] = files[url] || await (await original(url)).arrayBuffer();
                    const all = files[url];
                    const asked = /bytes=(\d+)-(\d*)/.exec(range);
                    const start = Number(asked[1]);
                    const end = Math.min(asked[2] ? Number(asked[2]) : all.byteLength - 1, all.byteLength - 1);
                    window.rangesAnswered += 1;
                    return new Response(all.slice(start, end + 1), {
                        status: 206,
                        headers: { 'Content-Range': 'bytes ' + start + '-' + end + '/' + all.byteLength, 'Content-Type': 'video/mp4' },
                    });
                };
            JS);

            $play = function (string $url) use ($tv): array {
                return $tv->script(<<<JS
                    return new Promise((resolve) => {
                        const box = document.createElement('div');
                        box.style.cssText = 'position:fixed;left:0;top:0;width:180px;height:320px;z-index:99';
                        const v = document.createElement('decoded-video');
                        v.dataset.pieceBytes = '700';
                        v.src = '{$url}';
                        v.autoplay = true;
                        const seen = [];
                        let shape = null, top = null, bottom = null;
                        v.addEventListener('loadeddata', () => {
                            seen.push('loadeddata');
                            const c = v.querySelector('canvas');
                            const read = (y) => Array.from(c.getContext('2d').getImageData(c.width / 2, y, 1, 1).data.slice(0, 3));
                            shape = [c.width, c.height];
                            top = read(Math.round(c.height * 0.1));
                            bottom = read(Math.round(c.height * 0.9));
                        });
                        // Never a key called "error": WebDriver takes an answer holding one for its own failure.
                        const done = (what) => () => { seen.push(what); resolve({ seen, shape, top, bottom, reads: v.reads, drawn: v.drawnFrames, length: v.duration, at: v.currentTime, why: v.error && v.error.message }); box.remove(); };
                        v.addEventListener('ended', done('ended'));
                        v.addEventListener('error', done('error'));
                        box.appendChild(v);
                        document.body.appendChild(box);
                    });
                JS)[0];
            };

            $files = [
                'portrait-red-top.mp4' => 'its index first',
                'portrait-red-top-index-at-end.mp4' => 'its index last',
                'lying-with-a-turn-flag.mp4' => 'lying, with a turn in its header',
                // Its index says 0 seconds: taken for its length, the clock never left the first frame.
                'portrait-red-top-fragmented.mp4' => 'fragmented, its index saying no length',
            ];

            foreach ($files as $file => $what) {
                $result = $play($urls[$file]);

                $this->assertSame(['loadeddata', 'ended'], $result['seen'], "{$file} ({$what}) plays to its end: ".json_encode($result));
                $this->assertSame([360, 640], $result['shape'], "{$file} is shown upright, 360 x 640");
                $this->assertSame('red', $this->colourOf($result['top']), "{$file}: the top is red");
                $this->assertSame('blue', $this->colourOf($result['bottom']), "{$file}: the bottom is blue");
                $this->assertGreaterThan(3, $result['reads'], "{$file} was read in pieces");
                $this->assertGreaterThan(60, $result['drawn'], "{$file} drew its frames");
                $this->assertEqualsWithDelta(4, $result['length'], 0.3, "{$file} lasts its four seconds");
                $this->assertEqualsWithDelta($result['length'], $result['at'], 0.1, "{$file} ended at its end");
            }

            $result = $play($urls['broken']);
            $this->assertSame(['error'], $result['seen'], 'a file that is no video says so: '.json_encode($result));
        });
    }

    public function test_a_decoded_video_moves_the_loop_on_and_a_landscape_screen_or_a_set_told_no_keeps_the_video_element(): void
    {
        [$screen] = $this->screenPlaying('portrait', ['portrait-red-top-2s.mp4']);
        $picture = Media::factory()->create([
            'organization_id' => $screen->organization_id,
            'title' => 'after',
            'type' => Media::TYPE_IMAGE,
            'mime_type' => 'image/png',
            'path' => $this->putImage("media/{$screen->organization_id}/after.png", 20, 200, 20),
            'thumbnail_path' => null,
        ]);
        PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $picture->id, 'position' => 1, 'duration_seconds' => 10]);

        $this->browse(function (Browser $tv) use ($screen) {
            // Decoded: it ends after its two seconds, and the picture comes on.
            $this->startTheSet($tv, $screen, '1');
            $this->waitForDecodedFrames($tv, 5);
            $tv->waitUntil("!!document.querySelector('.media-layer:not([hidden]) img[src*=\"after.png\"]')", 15, 'the loop never moved on from the decoded video');

            // Told no by the set itself: a <video>, as before.
            $this->startTheSet($tv, $screen, '0');
            $tv->waitUntil("!!document.querySelector('.media-layer video')", 15, 'a set told no should play a <video>');
            $this->assertSame(0, $tv->script("return document.querySelectorAll('decoded-video').length;")[0]);

            // A landscape screen never needs it.
            $screen->update(['orientation' => 'landscape']);
            $this->startTheSet($tv, $screen, '1');
            $tv->waitUntil("!!document.querySelector('.media-layer video')", 15, 'a landscape screen should play a <video>');
            $this->assertSame(0, $tv->script("return document.querySelectorAll('decoded-video').length;")[0]);
        });
    }

    public function test_the_next_video_on_a_turned_screen_is_ready_before_the_one_on_screen_ends_so_the_switch_is_a_swap(): void
    {
        // The owner's Fire TV Stick, 2026-11-02: the next video was made only once the one on screen had ended, and
        // reading its file and starting the Fire TV's decoder held the last picture for 0.53 to 0.62 s at every switch,
        // the jerk the owner saw. Here the decoder gives its first frame 0.6 s late, as slow as the stick's: the next
        // video comes on within a frame of the last one's end all the same, from its first frame, and never more than
        // two decoded videos are on the page.
        [$screen] = $this->screenPlaying('portrait', ['portrait-red-top.mp4', 'portrait-red-top-2s.mp4'], everyFile: true);

        $this->browse(function (Browser $tv) use ($screen) {
            $this->watchTheSwitches($tv);
            $this->startTheSet($tv, $screen, '1');

            $switches = $this->switchesSeen($tv, 4);
            foreach ($switches as [$gap, $from]) {
                $this->assertLessThan(100, $gap, 'the next video came on '.round($gap).' ms after the last one ended: '.json_encode($switches));
                $this->assertLessThan(0.15, $from, 'it came on from its first frame: '.json_encode($switches));
            }
            $this->assertLessThanOrEqual(2, $tv->script('return window.__switches.most;')[0], 'no more than two decoded videos at once');
        });
    }

    public function test_a_one_video_playlist_on_a_turned_screen_starts_again_with_no_hold(): void
    {
        // Roadstar's two kitchen TVs play one menu video each, over and over: every round was the same half-second hold.
        [$screen] = $this->screenPlaying('portrait', ['portrait-red-top-2s.mp4']);

        $this->browse(function (Browser $tv) use ($screen) {
            $this->watchTheSwitches($tv);
            $this->startTheSet($tv, $screen, '1');

            $switches = $this->switchesSeen($tv, 3);
            foreach ($switches as [$gap, $from]) {
                $this->assertLessThan(100, $gap, 'the video came on again '.round($gap).' ms after it ended: '.json_encode($switches));
                $this->assertLessThan(0.15, $from, 'it started again from its first frame: '.json_encode($switches));
            }
            $this->assertLessThanOrEqual(2, $tv->script('return window.__switches.most;')[0], 'no more than two decoded videos at once');
        });
    }

    public function test_a_break_between_two_turned_videos_lets_the_one_made_ready_go_and_the_next_comes_on_after_it(): void
    {
        // The next video waits behind the one on screen with a decoder of its own (prepareNext). A break due meanwhile
        // plays first, between the two (2026-10-23), and nothing waits behind it: its advert, and then the next video
        // from its start. The dusk environment brings a break round every few seconds; the advert is a picture.
        [$screen] = $this->screenPlaying('portrait', ['portrait-red-top.mp4']);
        $screen->organization->update(['accepts_network_ads' => true]);
        $screen->update(['accepts_network_ads' => true]);
        Campaign::factory()->lasting(10)->create([
            'name' => 'Coca-Cola', 'path' => $this->putImage('campaigns/advert.png', 220, 30, 40),
            'thumbnail_path' => null, 'mime_type' => 'image/png',
        ])->screens()->attach($screen);

        $this->browse(function (Browser $tv) use ($screen) {
            $this->startTheSet($tv, $screen, '1');
            $this->waitForDecodedFrames($tv, 5);

            $tv->waitUntil('!document.getElementById("layer-ad").hidden', 40, 'no break came');
            [$behind, $under] = $tv->script(<<<'JS'
                const shown = document.querySelector('#layer-a:not([hidden]), #layer-b:not([hidden])');
                const behind = document.querySelector('#layer-a[hidden] decoded-video, #layer-b[hidden] decoded-video');
                const under = shown && shown.querySelector('decoded-video');
                return [!!behind, under ? [under.paused, under.currentTime, under.duration] : null];
            JS)[0];
            $this->assertFalse($behind, 'a video made ready waits behind the break with its decoder');
            $this->assertNotNull($under, 'the video should still be under the advert');
            $this->assertGreaterThanOrEqual($under[2] - 0.1, $under[1], 'the break came before the video\'s end: '.json_encode($under));

            // After the advert: the video again, in an element of its own, from its start, and playing.
            $tv->script("document.querySelector('.media-layer:not([hidden]) decoded-video').dataset.before = 'yes';");
            $tv->waitUntil('document.getElementById("layer-ad").hidden', 30, 'the break never ended');
            $tv->waitUntil(
                "(function () { const v = document.querySelector('.media-layer:not([hidden]) decoded-video'); return !!v && v.dataset.before !== 'yes' && v.drawnFrames > 1; })()",
                20,
                'the next video did not come on after the break'
            );
            $at = $tv->script("return document.querySelector('.media-layer:not([hidden]) decoded-video').currentTime;")[0];
            $this->assertLessThan(3, $at, 'the video after the break started from its beginning');
            $this->assertSame(0, $tv->script('return document.getElementById("layer-ad").children.length;')[0]);
        });
    }

    public function test_a_first_frame_that_comes_out_as_an_empty_decoder_image_is_never_shown(): void
    {
        // The owner's Fire TV Stick, 2026-11-02: now and then the decoder hands out a frame whose image has not reached
        // its queue ("no buffers currently available in the reader queue"), and drawn it is green, the colour of an
        // empty YUV image, over a squashed picture. As the first frame of the video made ready behind the one on
        // screen, it waited there and came on at the switch: 1 switch in 18. Here every decoder's first frame is that
        // green: the video comes on with the frame after it, and the canvas holds a picture when it says it has one.
        [$screen] = $this->screenPlaying('portrait', ['portrait-red-top.mp4']);

        $this->browse(function (Browser $tv) use ($screen) {
            $this->onEveryPage($tv, <<<'JS'
                (() => {
                    if (typeof window.VideoDecoder !== 'function') return;
                    const seen = window.__emptyFirst = { shown: [] };
                    const Original = window.VideoDecoder;
                    window.VideoDecoder = function (init) {
                        let first = true;
                        return new Original({
                            output: (frame) => {
                                if (!first) return init.output(frame);
                                first = false;
                                const canvas = new OffscreenCanvas(frame.displayWidth, frame.displayHeight);
                                const context = canvas.getContext('2d');
                                context.fillStyle = 'rgb(0, 135, 0)';
                                context.fillRect(0, 0, canvas.width, canvas.height);
                                const empty = new VideoFrame(canvas, { timestamp: frame.timestamp, duration: frame.duration || undefined });
                                frame.close();
                                init.output(empty);
                            },
                            error: init.error,
                        });
                    };
                    window.VideoDecoder.isConfigSupported = (config) => Original.isConfigSupported(config);
                    // What the canvas holds the moment it says its first frame is drawn.
                    window.addEventListener('loadeddata', (event) => {
                        const canvas = event.target.tagName === 'DECODED-VIDEO' && event.target.querySelector('canvas');
                        if (!canvas) return;
                        const [r, g, b] = canvas.getContext('2d').getImageData(canvas.width >> 1, canvas.height >> 1, 1, 1).data;
                        seen.shown.push([r, g, b, event.target.emptyFirstFrames]);
                    }, true);
                })();
            JS);

            $this->startTheSet($tv, $screen, '1');
            $tv->waitUntil('window.__emptyFirst && window.__emptyFirst.shown.length > 0', 20, 'the decoded video never said it had a frame');

            [$r, $g, $b, $skipped] = $tv->script('return window.__emptyFirst.shown[0];')[0];
            $this->assertFalse($r < 12 && $g > 115 && $g < 155 && $b < 12, "the first frame shown was the empty green: rgb({$r}, {$g}, {$b})");
            $this->assertSame(1, $skipped, 'the empty frame was let go and the next one shown');
        });
    }

    public function test_a_turned_video_whose_first_frame_is_slow_to_come_still_plays_to_its_end(): void
    {
        // The stick, 2026-11-02: a five-minute video from a server that sent the whole file for every piece asked for
        // took 9.5 s to show its first frame, and its backstop, counted from when it was asked for, cut it 4.5 s before
        // its end. Here the first frame of every video comes 6 s late: the four-second video still ends by itself,
        // before the next one comes on.
        [$screen] = $this->screenPlaying('portrait', ['portrait-red-top.mp4', 'portrait-red-top-2s.mp4'], everyFile: true);

        $this->browse(function (Browser $tv) use ($screen) {
            $this->watchTheSwitches($tv, firstFrameLateMs: 6000);
            $this->startTheSet($tv, $screen, '1');

            $tv->waitUntil('window.__switches && window.__switches.shown.some(([, , name]) => /-2s/.test(name))', 40, 'the two-second video never came on');
            $ended = $tv->script('return window.__switches.endedNames;')[0];
            $this->assertNotEmpty(array_filter($ended, fn (string $name): bool => str_contains($name, 'portrait-red-top.mp4')), 'the four-second video was cut before its end: '.json_encode($ended));
        });
    }

    /**
     * Before the page loads: the decoder gives its first frame late — by default 0.6 s, as a Fire TV Stick's does (some
     * 0.3 s, and the file read before it) — and every decoded video's end and every layer that comes on is timed.
     */
    private function watchTheSwitches(Browser $tv, int $firstFrameLateMs = 600): void
    {
        $this->onEveryPage($tv, str_replace('FIRST_FRAME_LATE_MS', (string) $firstFrameLateMs, <<<'JS'
            (() => {
                if (typeof window.VideoDecoder !== 'function') return;
                const seen = window.__switches = { ended: [], endedNames: [], shown: [], most: 0 };
                const Original = window.VideoDecoder;
                window.VideoDecoder = function (init) {
                    let released = false, waiting = null;
                    const held = [];
                    return new Original({
                        output: (frame) => {
                            if (released) return init.output(frame);
                            held.push(frame);
                            if (!waiting) waiting = setTimeout(() => { released = true; held.splice(0).forEach((f) => init.output(f)); }, FIRST_FRAME_LATE_MS);
                        },
                        error: init.error,
                    });
                };
                window.VideoDecoder.isConfigSupported = (config) => Original.isConfigSupported(config);
                window.addEventListener('ended', (event) => {
                    if (event.target.tagName !== 'DECODED-VIDEO') return;
                    seen.ended.push(performance.now());
                    seen.endedNames.push(event.target.dataset.item || '');
                }, true);
                const watch = () => {
                    for (const id of ['layer-a', 'layer-b']) {
                        const layer = document.getElementById(id);
                        if (!layer || layer.__watched) continue;
                        layer.__watched = true;
                        new MutationObserver(() => {
                            const video = layer.querySelector('decoded-video');
                            if (!layer.hidden && video) seen.shown.push([performance.now(), video.currentTime, video.dataset.item || '']);
                        }).observe(layer, { attributes: true, attributeFilter: ['hidden'] });
                    }
                    seen.most = Math.max(seen.most, document.querySelectorAll('decoded-video').length);
                    requestAnimationFrame(watch);
                };
                document.addEventListener('DOMContentLoaded', watch);
            })();
        JS));
    }

    /**
     * Once this many decoded videos have ended: for each, how long after its end the next layer came on (ms), and
     * where the video in it stood then (s).
     *
     * @return list<array{0: float, 1: float}>
     */
    private function switchesSeen(Browser $tv, int $count): array
    {
        try {
            $tv->waitUntil("window.__switches && window.__switches.ended.length >= {$count} && window.__switches.shown.length > {$count}", 40);
        } catch (TimeoutException) {
            $this->fail('the videos never ended '.$count.' times: '.json_encode($tv->script(<<<'JS'
                return {
                    seen: window.__switches,
                    layers: ['layer-a', 'layer-b'].map((id) => {
                        const layer = document.getElementById(id);
                        return { id, hidden: layer.hidden, videos: [...layer.children].map((v) => ({ tag: v.tagName, paused: v.paused, ready: v.readyState, at: v.currentTime, duration: v.duration, drawn: v.drawnFrames })) };
                    }),
                };
            JS)[0]));
        }

        // A switch never seen reads as 99999, never as Infinity, which reaches PHP as null and would pass any "less than".
        return $tv->script(<<<JS
            const seen = window.__switches;
            return seen.ended.slice(0, {$count}).map((at) => {
                const next = seen.shown.find(([shown]) => shown >= at);
                return next ? [next[0] - at, next[1]] : [99999, 99999];
            });
        JS)[0];
    }

    public function test_an_ad_page_s_own_video_on_a_turned_screen_is_decoded_inside_the_page_turned_with_it_and_looping(): void
    {
        // The owner's Fire TV Stick, 2026-11-03: a page's background video was a <video>, which the Fire TV takes to its
        // hardware plane: sideways on a portrait screen, black on a flipped one. On a set that decodes, the player puts
        // its own decoder in the video's place inside the page: the picture turns with the stage, goes round and round
        // as the page's video did, and is let go when the page leaves.
        [$screen] = $this->screenPlaying('portrait', ['portrait-red-top-2s.mp4']);
        PlaylistItem::query()->delete();
        $page = $this->pageOverVideo($screen->organization, 'portrait-red-top-2s.mp4', BuilderAd::PORTRAIT);
        PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $page->id, 'position' => 0, 'duration_seconds' => null]);
        $picture = Media::factory()->create([
            'organization_id' => $screen->organization_id, 'title' => 'After', 'type' => Media::TYPE_IMAGE, 'mime_type' => 'image/png',
            'path' => $this->putImage("media/{$screen->organization_id}/after.png", 40, 160, 60), 'thumbnail_path' => null,
        ]);
        PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $picture->id, 'position' => 1, 'duration_seconds' => 10]);

        $this->browse(function (Browser $tv) use ($screen) {
            $this->startTheSet($tv, $screen, '1');
            // A page is the player's own origin only where the worker keeps the set (§15): then its videos can be decoded.
            $tv->waitUntil('!!navigator.serviceWorker.controller', 20, 'no worker took the page over');
            $tv->refresh();

            $frame = "document.querySelector('.media-layer:not([hidden]) iframe')";
            $inPage = "(({$frame} || {}).contentDocument || document.createElement('i'))";
            $tv->waitUntil("{$inPage}.querySelector('decoded-video') && {$inPage}.querySelector('decoded-video').drawnFrames > 10", 30, 'the page\'s video was not decoded on the turned screen');

            // Fitted as the page's video was (a cover layer): read once the video was out of the page, it was a stretch.
            $this->assertSame('cover', $tv->script("return getComputedStyle({$inPage}.querySelector('decoded-video canvas')).objectFit;")[0]);

            // The page's own <video> let its file go: nothing but the decoder plays it.
            $this->assertSame([], $tv->script("return [...{$inPage}.querySelectorAll('video')].map((v) => v.getAttribute('src'));")[0]);

            // Round and round: the two-second file plays a second time and more, never stopping at its end.
            $tv->waitUntil("{$inPage}.querySelector('decoded-video').rounds >= 2", 10, 'the page\'s video did not loop');
            $drawn = $tv->script("return {$inPage}.querySelector('decoded-video').drawnFrames;")[0];
            $tv->pause(700);
            $this->assertGreaterThan($drawn + 10, $tv->script("return {$inPage}.querySelector('decoded-video').drawnFrames;")[0], 'it stopped drawing after a round');

            // Turned with the stage: Portrait (rotated 90°) puts the picture's top, red, on the right of the panel.
            [$left, $right] = $this->panelHalves($tv);
            $this->assertSame('blue', $left, 'the left half of the panel is the video\'s bottom');
            $this->assertSame('red', $right, 'the right half of the panel is the video\'s top');

            // The page goes: its decoder goes with it, once the picture after it has faded in over it (Fade, every
            // screen's own, takes half a second).
            $tv->waitUntil("!!document.querySelector('.media-layer:not([hidden]) img')", 20, 'the picture after the page never came on');
            $tv->waitUntil("[...document.querySelectorAll('iframe')].length === 0", 3, 'the page\'s frame stayed behind after its fade');
            $this->assertSame(0, $tv->script("return document.querySelectorAll('decoded-video').length;")[0], 'a decoder outlived its page');
        });
    }

    public function test_an_ad_page_s_own_video_on_a_landscape_screen_stays_the_pages_video(): void
    {
        [$screen] = $this->screenPlaying('landscape', ['portrait-red-top-2s.mp4']);
        PlaylistItem::query()->delete();
        $page = $this->pageOverVideo($screen->organization, 'portrait-red-top-2s.mp4', BuilderAd::LANDSCAPE);
        PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $page->id, 'position' => 0, 'duration_seconds' => null]);

        $this->browse(function (Browser $tv) use ($screen) {
            $this->startTheSet($tv, $screen, '1');
            $tv->waitUntil('!!navigator.serviceWorker.controller', 20, 'no worker took the page over');
            $tv->refresh();

            $inPage = "((document.querySelector('.media-layer:not([hidden]) iframe') || {}).contentDocument || document.createElement('i'))";
            $tv->waitUntil("!!{$inPage}.querySelector('video') && !{$inPage}.querySelector('video').paused", 30, 'the page\'s video never played');
            $this->assertSame(0, $tv->script("return {$inPage}.querySelectorAll('decoded-video').length;")[0]);
        });
    }

    /** A published Ad Builder page of this shape over a background video made of the fixture, for ten seconds. */
    private function pageOverVideo(Organization $organization, string $file, string $orientation): Media
    {
        $path = "builder/{$organization->id}/assets/{$file}";
        Storage::disk('public')->put($path, file_get_contents(base_path(self::FIXTURES.$file)));
        $clip = BuilderAsset::factory()->video()->create(['organization_id' => $organization->id, 'path' => $path, 'thumbnail_path' => null]);

        $document = BuilderAd::blankDocument($orientation);
        $document['duration'] = 10;
        $document['stage']['background']['layers'] = [['id' => 'bg_video', 'type' => 'video', 'assetId' => $clip->id, 'fit' => 'cover', 'visible' => true, 'opacity' => 1]];
        $ad = BuilderAd::create(['organization_id' => $organization->id, 'name' => 'Page over a video', 'orientation' => $orientation, 'document' => $document]);

        return app(AdPublisher::class)->publish($ad);
    }

    /**
     * A screen of the given orientation with these fixtures on the Dusk disk: the first on its playlist, or every one
     * of them, in order.
     *
     * @param  list<string>  $files
     * @return array{0: Screen, 1: array<string, string>}
     */
    private function screenPlaying(string $orientation, array $files, bool $everyFile = false): array
    {
        $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
        $screen = Screen::factory()->withToken('decoded-token')->create([
            'organization_id' => $organization->id,
            'name' => 'Menu TV',
            'orientation' => $orientation,
        ]);

        $urls = [];
        foreach ($files as $position => $file) {
            $path = "media/{$organization->id}/{$file}";
            Storage::disk('public')->put($path, file_get_contents(base_path(self::FIXTURES.$file)));
            $urls[$file] = Storage::disk('public')->url($path);

            $media = Media::factory()->video()->create([
                'organization_id' => $organization->id,
                'title' => pathinfo($file, PATHINFO_FILENAME),
                'path' => $path,
                'thumbnail_path' => null,
                'size' => filesize(base_path(self::FIXTURES.$file)),
                'width' => 360,
                'height' => 640,
                'orientation' => 'portrait',
                'duration_seconds' => str_contains($file, '2s') ? 2 : 4,
            ]);

            if ($position === 0 || $everyFile) {
                PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $media->id, 'position' => $position, 'duration_seconds' => null]);
            }
        }

        return [$screen, $urls];
    }

    /** A 1920 x 1080 panel, paired as this screen, with the set's own word on decoding ('1' or '0'), or none (null). */
    private function startTheSet(Browser $tv, Screen $screen, ?string $decode): void
    {
        $tv->resize(1920, 1080);
        [$width, $height] = $tv->script('return [window.innerWidth, window.innerHeight];')[0];
        $tv->resize(1920 + (1920 - $width), 1080 + (1080 - $height));

        $tv->visit('/login');
        $tv->script("localStorage.clear(); localStorage.setItem('signage.device.token', 'decoded-token');");
        if ($decode !== null) {
            $tv->script("localStorage.setItem('signage.decode_turned_video', '{$decode}');");
        }
        $tv->visit('/player');
        $tv->waitUntil("document.getElementById('stage').dataset.orientation === '{$screen->fresh()->orientation}'", 15, 'the stage never took the screen\'s orientation');
    }

    private function waitForDecodedFrames(Browser $tv, int $frames): void
    {
        $tv->waitUntil(
            "(function () { const v = document.querySelector('.media-layer:not([hidden]) decoded-video'); return !!v && v.drawnFrames > {$frames}; })()",
            20,
            'no decoded video drew frames on the stage'
        );
    }

    /** The colour of the panel's left and right halves, read from a screenshot of the page. */
    private function panelHalves(Browser $tv): array
    {
        $shot = imagecreatefromstring($tv->driver->takeScreenshot());
        $read = function (int $x) use ($shot): string {
            $rgb = imagecolorat($shot, $x, intdiv(imagesy($shot), 2));

            return $this->colourOf([($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF]);
        };

        return [$read(intdiv(imagesx($shot), 4)), $read(intdiv(imagesx($shot) * 3, 4))];
    }

    /** Red, blue or something else, for a pixel of the red-over-blue fixtures. */
    private function colourOf(?array $rgb): string
    {
        if ($rgb === null) {
            return 'nothing';
        }

        [$r, $g, $b] = $rgb;

        return match (true) {
            $r > 150 && $g < 80 && $b < 80 => 'red',
            $b > 150 && $r < 80 && $g < 80 => 'blue',
            default => sprintf('rgb(%d, %d, %d)', $r, $g, $b),
        };
    }
}
