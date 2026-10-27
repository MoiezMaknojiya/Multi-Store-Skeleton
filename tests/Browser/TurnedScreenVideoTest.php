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
 * On a turned screen a video is drawn a touch short of opaque (Roadstar 4, 2026-10-27).
 *
 * Chromium puts a video on a hardware plane of its own only while its quad is fully opaque (viz,
 * OverlayCandidateFactory::FromDrawQuad: kFailOpacity), and a Fire TV's plane never turns with the stage:
 * Roadstar's portrait televisions showed their video sideways whenever that plane was in use, and the right
 * way up whenever the compositor drew it. So the player's own CSS gives a video on a turned screen an opacity
 * of .999 — invisible to the eye, and enough to keep it off that plane — while a landscape screen keeps it.
 * Only a Fire TV can show the picture turning; this holds the rule, on every orientation.
 */
class TurnedScreenVideoTest extends DuskTestCase
{
    use DatabaseMigrations;

    private const ON_SCREEN_VIDEO = '#layer-a:not([hidden]) video, #layer-b:not([hidden]) video';

    public function test_a_turned_screen_draws_its_video_a_touch_short_of_opaque_and_a_flat_one_opaque(): void
    {
        $organization = Organization::factory()->create(['name' => 'Roadstar']);
        $screens = [];

        foreach (['landscape', 'portrait', 'portrait_flipped', 'landscape_flipped'] as $orientation) {
            $screens[$orientation] = Screen::factory()->withToken("{$orientation}-token")->create([
                'organization_id' => $organization->id, 'name' => ucfirst($orientation).' TV', 'orientation' => $orientation,
            ]);
        }

        $this->browse(function (Browser $tv) use ($organization, $screens) {
            $tv->visit('/login');
            $clip = $this->recordClip($tv, "media/{$organization->id}/burger.mp4");

            foreach ($screens as $screen) {
                $video = Media::factory()->create([
                    'organization_id' => $organization->id, 'title' => 'Burger',
                    'type' => Media::TYPE_VIDEO, 'mime_type' => 'video/mp4', 'path' => $clip, 'thumbnail_path' => null,
                    'duration_seconds' => 3, 'width' => 180, 'height' => 320, 'orientation' => 'portrait',
                ]);
                PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $video->id, 'position' => 0, 'duration_seconds' => 3]);
            }

            foreach (['landscape' => '1', 'portrait' => '0.999', 'portrait_flipped' => '0.999', 'landscape_flipped' => '0.999'] as $orientation => $opacity) {
                $tv->visit('/login');
                $tv->script("localStorage.clear(); localStorage.setItem('signage.device.token', '{$orientation}-token');");
                $tv->visit('/player');
                $tv->waitUsing(30, 200, fn () => $tv->script('const v = document.querySelector("'.self::ON_SCREEN_VIDEO.'"); return !!v && !v.paused;')[0], "the video did not come on, {$orientation}");

                $shown = $tv->script(<<<'JS'
                    const video = document.querySelector('#layer-a:not([hidden]) video, #layer-b:not([hidden]) video');
                    const style = getComputedStyle(video);
                    return { orientation: document.getElementById('stage').dataset.orientation, opacity: style.opacity, visibility: style.visibility, playing: !video.paused };
                JS)[0];

                $this->assertSame($orientation, $shown['orientation']);
                $this->assertSame($opacity, $shown['opacity'], "{$orientation}: the video's opacity");
                $this->assertSame('visible', $shown['visibility']);
                $this->assertTrue($shown['playing']);
            }

            $tv->visit('/login');
            $tv->script('localStorage.clear();');
        });
    }

    /** A real three-second portrait clip on the public disk at $path. */
    private function recordClip(Browser $tv, string $path): string
    {
        $tv->script(<<<'JS'
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
                    context.fillStyle = frame++ % 2 ? '#a40' : '#840';
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
            $this->markTestSkipped('This Chrome build cannot record MP4, so there is no real video to play.');
        }

        Storage::disk('public')->put($path, base64_decode(explode(',', $clip, 2)[1], true));

        return $path;
    }
}
