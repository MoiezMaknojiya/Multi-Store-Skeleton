<?php

namespace Tests\Browser;

use App\Models\Media;
use App\Models\Organization;
use App\Models\PlaylistItem;
use App\Models\Screen;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
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
        // stick's log said "no buffers currently available in the reader queue". Every frame the decoder hands out is
        // counted here, and every one closed: two held or asked for at most, and one already inside the decoder may
        // still come out after the asking stops. The decoder before held ten.
        [$screen] = $this->screenPlaying('portrait', ['portrait-red-top.mp4']);

        $this->browse(function (Browser $tv) use ($screen) {
            (new ChromeDevToolsDriver($tv->driver))->execute('Page.addScriptToEvaluateOnNewDocument', ['source' => <<<'JS'
                (() => {
                    if (typeof window.VideoDecoder !== 'function') return;
                    const held = window.__heldFrames = { now: 0, most: 0, out: 0 };
                    const close = VideoFrame.prototype.close;
                    VideoFrame.prototype.close = function () {
                        if (!this.__closed) { this.__closed = true; held.now -= 1; }
                        return close.call(this);
                    };
                    const Original = window.VideoDecoder;
                    window.VideoDecoder = function (init) {
                        return new Original({
                            output: (frame) => { held.out += 1; held.now += 1; held.most = Math.max(held.most, held.now); init.output(frame); },
                            error: init.error,
                        });
                    };
                    window.VideoDecoder.isConfigSupported = (config) => Original.isConfigSupported(config);
                })();
            JS]);

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

    /**
     * A screen of the given orientation whose playlist is these fixtures, in order, on the Dusk disk.
     *
     * @param  list<string>  $files
     * @return array{0: Screen, 1: array<string, string>}
     */
    private function screenPlaying(string $orientation, array $files): array
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

            if ($position === 0) {
                PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $media->id, 'position' => 0, 'duration_seconds' => null]);
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
