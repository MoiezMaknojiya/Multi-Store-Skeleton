<?php

namespace Tests\Browser;

use App\Models\Media;
use App\Models\Organization;
use App\Models\PlaylistItem;
use App\Models\Screen;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Storage;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * A video on a turned screen is shown through a canvas (Roadstar 4, 2026-10-27).
 *
 * A Fire TV's WebView plays a video on a layer of its own that the stage's rotation never reaches (Amazon's
 * answer on its developer forum: a CSS transform does nothing to a video there), and Roadstar's portrait
 * televisions showed one round in three sideways. So on a turned screen the player copies the video's frames
 * onto a canvas, which is turned like any picture, and keeps the element itself out of sight — still playing,
 * still the node the loop plays by. A landscape screen shows the video itself, as before. And a WebView that
 * hands the canvas nothing — black for six seconds while the video plays — gets the video shown as before.
 */
class TurnedScreenVideoTest extends DuskTestCase
{
    use DatabaseMigrations;

    private const ON_SCREEN_VIDEO = '#layer-a:not([hidden]) video, #layer-b:not([hidden]) video';

    private const ON_SCREEN_CANVAS = '#layer-a:not([hidden]) canvas, #layer-b:not([hidden]) canvas';

    public function test_a_turned_screen_draws_its_video_on_a_canvas_and_a_flat_one_shows_the_video(): void
    {
        $organization = Organization::factory()->create(['name' => 'Roadstar']);
        $turned = Screen::factory()->withToken('turned-token')->create([
            'organization_id' => $organization->id, 'name' => 'Kitchen TV', 'orientation' => 'portrait',
        ]);
        $flat = Screen::factory()->withToken('flat-token')->create([
            'organization_id' => $organization->id, 'name' => 'Counter TV', 'orientation' => 'landscape',
        ]);
        $dark = Screen::factory()->withToken('dark-token')->create([
            'organization_id' => $organization->id, 'name' => 'Back TV', 'orientation' => 'portrait_flipped',
        ]);

        $this->browse(function (Browser $tv) use ($organization, $turned, $flat, $dark) {
            $tv->visit('/login');

            $burger = $this->recordClip($tv, "media/{$organization->id}/burger.mp4", ['#a40', '#840']);
            $night = $this->recordClip($tv, "media/{$organization->id}/night.mp4", ['#000', '#000']);

            foreach ([[$turned, $burger], [$flat, $burger], [$dark, $night]] as [$screen, $clip]) {
                $video = Media::factory()->create([
                    'organization_id' => $organization->id, 'title' => ucfirst(basename($clip, '.mp4')),
                    'type' => Media::TYPE_VIDEO, 'mime_type' => 'video/mp4', 'path' => $clip, 'thumbnail_path' => null,
                    'duration_seconds' => 3, 'width' => 180, 'height' => 320, 'orientation' => 'portrait',
                ]);
                PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $video->id, 'position' => 0, 'duration_seconds' => 3]);
            }

            // -- Turned: the video plays out of sight, and the canvas beside it shows its frames, turned with the stage --
            $this->playOn($tv, 'turned-token');
            $this->assertSame('portrait', $tv->script('return document.getElementById("stage").dataset.orientation;')[0]);
            $tv->waitUsing(10, 200, fn () => $tv->script('return !!document.querySelector("'.self::ON_SCREEN_CANVAS.'");')[0], 'no canvas was drawn for the video');

            $shown = $tv->script(<<<JS
                const video = document.querySelector('{$this->selector(self::ON_SCREEN_VIDEO)}');
                const canvas = document.querySelector('{$this->selector(self::ON_SCREEN_CANVAS)}');
                return {
                    drawn: video.dataset.drawn, hidden: getComputedStyle(video).visibility, playing: !video.paused,
                    beside: video.nextElementSibling === canvas, first: video.parentElement.firstElementChild === video,
                    videos: document.querySelectorAll('video').length, canvases: document.querySelectorAll('canvas').length,
                };
            JS)[0];
            $this->assertSame('canvas', $shown['drawn']);
            $this->assertSame('hidden', $shown['hidden'], 'the video element itself is still in sight');
            $this->assertTrue($shown['playing'], 'the video stopped once it was drawn on a canvas');
            $this->assertTrue($shown['beside'] && $shown['first'], 'the video is no longer the first node of its layer, with the canvas beside it');
            $this->assertSame([1, 1], [$shown['videos'], $shown['canvases']]);

            // The canvas is getting the picture: the clip's colours, not black.
            $tv->waitUsing(10, 250, fn () => $this->litPixels($tv) > 0, 'the canvas stayed black');

            // The clip comes round again in place: the same canvas keeps drawing, nothing is added or given up.
            $tv->script('window.__canvas = document.querySelector("'.self::ON_SCREEN_CANVAS.'"); window.__starts = 0; document.addEventListener("play", () => { window.__starts += 1; }, true);');
            $tv->waitUsing(20, 200, fn () => $tv->script('return window.__starts >= 1;')[0], 'the clip did not come round again');
            $tv->pause(1500);
            $again = $tv->script(<<<JS
                const canvas = document.querySelector('{$this->selector(self::ON_SCREEN_CANVAS)}');
                const video = document.querySelector('{$this->selector(self::ON_SCREEN_VIDEO)}');
                return { same: canvas === window.__canvas, drawn: video.dataset.drawn, canvases: document.querySelectorAll('canvas').length };
            JS)[0];
            $this->assertTrue($again['same'], 'the canvas was replaced when the clip came round');
            $this->assertSame('canvas', $again['drawn'], 'the canvas was given up although it was drawing');
            $this->assertSame(1, $again['canvases']);
            $this->assertGreaterThan(0, $this->litPixels($tv));

            // -- Flat: the video itself, as it always was --
            $this->playOn($tv, 'flat-token');
            $tv->pause(1500);
            $flatShown = $tv->script(<<<JS
                const video = document.querySelector('{$this->selector(self::ON_SCREEN_VIDEO)}');
                return { drawn: video.dataset.drawn || null, visible: getComputedStyle(video).visibility, canvases: document.querySelectorAll('canvas').length };
            JS)[0];
            $this->assertNull($flatShown['drawn']);
            $this->assertSame('visible', $flatShown['visible']);
            $this->assertSame(0, $flatShown['canvases'], 'a landscape screen drew a canvas it does not need');

            // -- Turned, with a canvas that gets nothing: the video is shown instead, and keeps playing --
            $this->playOn($tv, 'dark-token');
            $this->assertSame('portrait_flipped', $tv->script('return document.getElementById("stage").dataset.orientation;')[0]);
            $tv->waitUsing(10, 200, fn () => $tv->script('return !!document.querySelector("'.self::ON_SCREEN_CANVAS.'");')[0], 'no canvas was drawn for the dark video');
            $tv->waitUsing(20, 250, fn () => $tv->script('return document.querySelectorAll("canvas").length === 0;')[0], 'a canvas that got no picture was kept');
            $darkShown = $tv->script(<<<JS
                const video = document.querySelector('{$this->selector(self::ON_SCREEN_VIDEO)}');
                return { drawn: video.dataset.drawn || null, visible: getComputedStyle(video).visibility, playing: !video.paused };
            JS)[0];
            $this->assertNull($darkShown['drawn']);
            $this->assertSame('visible', $darkShown['visible'], 'the video was not shown once its canvas was given up');
            $this->assertTrue($darkShown['playing']);

            $tv->visit('/login');
            $tv->script('localStorage.clear();');
        });
    }

    /** The player, as the television with this token. */
    private function playOn(Browser $tv, string $token): void
    {
        $tv->visit('/login');
        $tv->script("localStorage.clear(); localStorage.setItem('signage.device.token', '{$token}');");
        $tv->visit('/player');
        $tv->waitUsing(30, 200, fn () => $tv->script('const v = document.querySelector("'.self::ON_SCREEN_VIDEO.'"); return !!v && !v.paused;')[0], 'the video did not come on');
    }

    /** How many of a few sampled pixels of the canvas on screen are lit. */
    private function litPixels(Browser $tv): int
    {
        return (int) $tv->script(<<<JS
            const canvas = document.querySelector('{$this->selector(self::ON_SCREEN_CANVAS)}');
            if (!canvas || !canvas.width) return 0;
            const pixels = canvas.getContext('2d').getImageData(0, 0, canvas.width, canvas.height).data;
            let lit = 0;
            for (let i = 0; i < pixels.length; i += 4 * 997) if (pixels[i] + pixels[i + 1] + pixels[i + 2] > 24) lit += 1;
            return lit;
        JS)[0];
    }

    /** A selector written into a single-quoted script string. */
    private function selector(string $selector): string
    {
        return str_replace("'", "\\'", $selector);
    }

    /** A real three-second portrait clip, its frames alternating between two colours, on the public disk at $path. */
    private function recordClip(Browser $tv, string $path, array $colours): string
    {
        $tv->script(<<<JS
            window.__clip = false;
            (async () => {
                const types = ['video/mp4;codecs=avc1.42E01E', 'video/mp4'];
                const mimeType = types.find((t) => MediaRecorder.isTypeSupported(t));
                if (!mimeType) { window.__clip = 'no-mp4-encoder'; return; }

                const canvas = document.createElement('canvas');
                canvas.width = 180; canvas.height = 320;
                const context = canvas.getContext('2d');
                let frame = 0;
                const draw = setInterval(() => {
                    context.fillStyle = frame++ % 2 ? '{$colours[0]}' : '{$colours[1]}';
                    context.fillRect(0, 0, 180, 320);
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
                setTimeout(() => recorder.stop(), 3000);
            })();
        JS);

        $tv->waitUsing(30, 300, fn () => $tv->script('return window.__clip;')[0] !== false);
        $clip = $tv->script('return window.__clip;')[0];

        if ($clip === 'no-mp4-encoder') {
            $this->markTestSkipped('This Chrome build cannot record MP4, so there is no real video to draw.');
        }

        Storage::disk('public')->put($path, base64_decode(explode(',', $clip, 2)[1], true));

        return $path;
    }
}
