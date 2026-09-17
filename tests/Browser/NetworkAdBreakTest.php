<?php

namespace Tests\Browser;

use App\Models\Campaign;
use App\Models\Media;
use App\Models\PlaylistItem;
use App\Models\Screen;
use App\Models\Store;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Storage;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The advertising break, on an actual television.
 *
 * This is the part that has to behave on a shop's wall for weeks without anybody
 * watching it, so it is tested for more than "the advert appeared":
 *
 *   · the shop's content PAUSES rather than restarting, and carries on from exactly
 *     where it stopped — a two-hour video resumes at 1:00:00
 *   · only one video decodes at a time, so a cheap box is never asked to play two
 *   · the advert's element is DESTROYED when the break ends. A hidden <video> keeps
 *     its decoder and its buffer, and a set left running would collect one an hour
 *   · a poll landing mid-break does not tear the screen down underneath the advert
 *
 * config/signage.php turns the interval right down in the dusk environment, so a
 * whole break happens in seconds instead of an hour.
 */
class NetworkAdBreakTest extends DuskTestCase
{
    use DatabaseMigrations;

    /** A real PNG on the (isolated) dusk disk, and the path it was written to. */
    private function putImage(string $path, int $r, int $g, int $b): string
    {
        $image = imagecreatetruecolor(640, 360);
        imagefilledrectangle($image, 0, 0, 640, 360, imagecolorallocate($image, $r, $g, $b));

        ob_start();
        imagepng($image);
        $binary = (string) ob_get_clean();
        imagedestroy($image);

        Storage::disk('public')->put($path, $binary);

        return $path;
    }

    /** A shop that agreed, a television cleared for advertising, and one campaign. */
    private function setUpScreen(string $token, int $adSeconds = 2): array
    {
        $store = Store::factory()->create(['name' => 'Alpha Mart', 'accepts_network_ads' => true]);
        $screen = Screen::factory()->withToken($token)->create([
            'store_id' => $store->id, 'name' => 'Counter TV', 'accepts_network_ads' => true,
        ]);

        $campaign = Campaign::factory()->lasting($adSeconds)->create([
            'name' => 'Coca-Cola', 'path' => $this->putImage('campaigns/advert.png', 220, 30, 40),
            'thumbnail_path' => null, 'mime_type' => 'image/png',
        ]);
        $campaign->screens()->attach($screen);

        return [$store, $screen, $campaign];
    }

    /**
     * An image on screen is interrupted, and comes back with the time it had LEFT.
     *
     * The advert's element is gone afterwards, not merely hidden.
     */
    public function test_an_advert_interrupts_the_shops_content_and_leaves_nothing_behind(): void
    {
        [$store, $screen] = $this->setUpScreen('ad-token');

        $poster = Media::factory()->create([
            'store_id' => $store->id, 'title' => 'Menu',
            'type' => Media::TYPE_IMAGE, 'mime_type' => 'image/png',
            'path' => $this->putImage("media/{$store->id}/menu.png", 20, 90, 200),
            'thumbnail_path' => null,
        ]);

        PlaylistItem::create([
            'screen_id' => $screen->id, 'media_id' => $poster->id,
            // Long enough that the break is certain to land in the middle of it.
            'position' => 0, 'duration_seconds' => 120,
        ]);

        $this->browse(function (Browser $tv) {
            $tv->visit('/login');
            $tv->script("localStorage.clear(); localStorage.setItem('signage.device.token', 'ad-token');");
            $tv->visit('/player');

            // -- The shop's own content ----------------------------------------
            $tv->waitUsing(30, 200, fn () => $tv->script(
                'return !!document.querySelector("#layer-a:not([hidden]) img, #layer-b:not([hidden]) img");'
            )[0]);

            // -- The break ------------------------------------------------------
            $tv->waitUsing(30, 200, fn () => $tv->script(
                'return !document.getElementById("layer-ad").hidden;'
            )[0]);

            // The advert really is on screen, and the content is still underneath it
            // rather than having been torn down.
            $this->assertSame(1, $tv->script('return document.getElementById("layer-ad").children.length;')[0]);
            $this->assertTrue($tv->script(
                'return !!document.querySelector("#layer-a:not([hidden]) img, #layer-b:not([hidden]) img");'
            )[0], 'the shop content should still be in the DOM, merely covered');

            // -- And afterwards --------------------------------------------------
            $tv->waitUsing(30, 200, fn () => $tv->script(
                'return document.getElementById("layer-ad").hidden;'
            )[0]);

            // Emptied, not just hidden. This is the whole difference between a set
            // that runs for a month and one that slows to a crawl.
            $this->assertSame(0, $tv->script('return document.getElementById("layer-ad").children.length;')[0],
                'the advert element should be destroyed when the break ends');

            // The shop's content is back on screen.
            $this->assertTrue($tv->script(
                'return !!document.querySelector("#layer-a:not([hidden]) img, #layer-b:not([hidden]) img");'
            )[0]);

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
     * A video is PAUSED and resumed from its own position — never restarted.
     *
     * The one that matters most: pause() keeps the element, its buffer and its
     * position, so resuming a long video costs nothing. Seeking back to a remembered
     * time would re-buffer, and on a cheap television that stutters or fails.
     */
    public function test_a_video_is_paused_and_carries_on_from_where_it_stopped(): void
    {
        [$store, $screen] = $this->setUpScreen('video-ad-token');

        $this->browse(function (Browser $tv) use ($store, $screen) {
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
            $path = "media/{$store->id}/clip.mp4";
            Storage::disk('public')->put($path, $binary);

            $video = Media::factory()->create([
                'store_id' => $store->id, 'title' => 'Long clip',
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

            // -- Let the FIRST break go by ----------------------------------------
            // The break is armed the moment the manifest lands, so it can arrive while
            // the video is still its first fraction of a second in — and "resumed at
            // 0.11 rather than 0.00" proves very little. Waiting for the second one
            // means the video is several seconds in when it is interrupted, and the
            // difference between carrying on and starting again is unmistakable.
            $tv->waitUsing(30, 200, fn () => $tv->script('return !document.getElementById("layer-ad").hidden;')[0]);
            $tv->waitUsing(30, 200, fn () => $tv->script('return document.getElementById("layer-ad").hidden;')[0]);

            // -- Interrupted ------------------------------------------------------
            $tv->waitUsing(30, 200, fn () => $tv->script('return !document.getElementById("layer-ad").hidden;')[0]);

            $paused = $tv->script("return document.querySelector('{$onScreen}')?.paused;")[0];
            $pausedAt = (float) $tv->script("return document.querySelector('{$onScreen}')?.currentTime ?? 0;")[0];

            $this->assertTrue($paused, 'the shop video must be paused while the advert plays');
            $this->assertGreaterThan(0.2, $pausedAt, 'the video should have been playing when it was interrupted');

            // Mark the element itself. Whether the clip happens to be one second or
            // ten seconds in when the break lands is luck; whether it is the SAME
            // element afterwards is the actual question — a restart builds a new one.
            $tv->script("document.querySelector('{$onScreen}').dataset.survived = 'yes';");

            // Nothing is decoding two videos at once: the advert here is an image, so
            // with the content paused there is nothing playing at all.
            $this->assertSame(0, $tv->script(
                'return [...document.querySelectorAll("video")].filter((v) => !v.paused).length;'
            )[0]);

            // -- And back --------------------------------------------------------
            $tv->waitUsing(30, 200, fn () => $tv->script('return document.getElementById("layer-ad").hidden;')[0]);

            $tv->waitUsing(10, 200, fn () => $tv->script("return document.querySelector('{$onScreen}')?.paused === false;")[0]);

            $resumedAt = (float) $tv->script("return document.querySelector('{$onScreen}')?.currentTime ?? 0;")[0];

            // The very same element is still there and still playing — it was paused,
            // not torn down and built again. This is what pause() buys: no seek, no
            // re-buffer, and a two-hour video that carries on at 1:00:00.
            $this->assertSame('yes', $tv->script("return document.querySelector('{$onScreen}')?.dataset.survived;")[0],
                'the video element was replaced — the content restarted instead of resuming');

            // And it carried on from where it stopped rather than rewinding.
            $this->assertGreaterThanOrEqual($pausedAt - 0.2, $resumedAt,
                "the video went backwards (paused at {$pausedAt}, came back at {$resumedAt})");

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
     * A television that carries no advertising is never interrupted.
     *
     * The consent switches are the whole basis of the deal with each shop, so this is
     * checked where it actually matters — on the set itself, not only in the API.
     */
    public function test_a_screen_that_carries_no_advertising_is_never_interrupted(): void
    {
        $store = Store::factory()->create(['accepts_network_ads' => true]);
        // The shop agreed; this particular television did not.
        $screen = Screen::factory()->withToken('clean-token')->create([
            'store_id' => $store->id, 'accepts_network_ads' => false,
        ]);

        $campaign = Campaign::factory()->lasting(2)->create([
            'path' => $this->putImage('campaigns/advert.png', 220, 30, 40), 'thumbnail_path' => null,
        ]);
        $campaign->screens()->attach($screen);

        $poster = Media::factory()->create([
            'store_id' => $store->id, 'type' => Media::TYPE_IMAGE, 'mime_type' => 'image/png',
            'path' => $this->putImage("media/{$store->id}/menu.png", 20, 90, 200), 'thumbnail_path' => null,
        ]);
        PlaylistItem::create([
            'screen_id' => $screen->id, 'media_id' => $poster->id, 'position' => 0, 'duration_seconds' => 120,
        ]);

        $this->browse(function (Browser $tv) {
            $tv->visit('/login');
            $tv->script("localStorage.clear(); localStorage.setItem('signage.device.token', 'clean-token');");
            $tv->visit('/player');

            $tv->waitUsing(30, 200, fn () => $tv->script(
                'return !!document.querySelector("#layer-a:not([hidden]) img, #layer-b:not([hidden]) img");'
            )[0]);

            // Well past several break intervals, and nothing has interrupted it.
            $tv->pause(9000);

            $this->assertTrue($tv->script('return document.getElementById("layer-ad").hidden;')[0],
                'a screen that carries no advertising was interrupted anyway');
            $this->assertSame(0, $tv->script('return document.getElementById("layer-ad").children.length;')[0]);

            $tv->visit('/login');
            $tv->script('localStorage.clear();');
        });
    }
}
