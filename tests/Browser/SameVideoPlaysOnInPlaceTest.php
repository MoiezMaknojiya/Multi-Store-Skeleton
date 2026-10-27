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
 * A video that comes round again plays again in the element already on screen, from its start (2026-10-27).
 *
 * Roadstar 4's portrait televisions each played one ten-second video, and on their Fire TV Sticks one round in
 * three was shown sideways, small, then two the right way up — again and again, a cycle of thirty seconds, the
 * player's poll. The player built a new <video> for every round and swapped the layers to show it, and every poll
 * wrote the stage's orientation and the page's notes again, unchanged. Now the same clip plays again in place,
 * and a poll that changes nothing writes nothing on the page.
 */
class SameVideoPlaysOnInPlaceTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_a_playlist_of_one_video_plays_it_again_in_the_same_element(): void
    {
        $organization = Organization::factory()->create(['name' => 'Roadstar']);
        $screen = Screen::factory()->withToken('one-video-token')->create([
            'organization_id' => $organization->id, 'name' => 'Kitchen TV', 'orientation' => 'portrait',
        ]);

        $this->browse(function (Browser $tv) use ($organization, $screen) {
            // -- A real three-second portrait clip ------------------------------
            $tv->visit('/login');
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
                $this->markTestSkipped('This Chrome build cannot record MP4, so there is no real video to loop.');
            }

            $path = "media/{$organization->id}/burger.mp4";
            Storage::disk('public')->put($path, base64_decode(explode(',', $clip, 2)[1], true));

            $video = Media::factory()->create([
                'organization_id' => $organization->id, 'title' => 'Burger',
                'type' => Media::TYPE_VIDEO, 'mime_type' => 'video/mp4', 'path' => $path, 'thumbnail_path' => null,
                'duration_seconds' => 3, 'width' => 180, 'height' => 320, 'orientation' => 'portrait',
            ]);
            PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $video->id, 'position' => 0, 'duration_seconds' => 3]);

            // -- On the television ------------------------------------------------
            $tv->script("localStorage.clear(); localStorage.setItem('signage.device.token', 'one-video-token');");
            $tv->visit('/player');

            $onScreen = '#layer-a:not([hidden]) video, #layer-b:not([hidden]) video';
            $tv->waitUsing(30, 200, fn () => $tv->script("const v = document.querySelector('{$onScreen}'); return !!v && !v.paused;")[0]);
            $this->assertSame('portrait', $tv->script('return document.getElementById("stage").dataset.orientation;')[0]);

            // The first warm-up's note is written once; what follows must be the polls alone.
            $tv->waitUntil('!!document.body.dataset.warmed', 30, 'the worker never said the cache was warmed');

            // From here on, every layer shown, hidden, emptied or filled is counted, every start of the clip, and
            // every attribute written on the stage or the page. The polls are counted by their requests.
            $tv->script(<<<'JS'
                const polls = () => performance.getEntriesByType('resource').filter((entry) => entry.name.includes('/device/playlist')).length;
                window.__loop = {
                    first: document.querySelector('#layer-a:not([hidden]) video, #layer-b:not([hidden]) video'),
                    changes: 0,
                    writes: [],
                    starts: 0,
                    pollsAtStart: polls(),
                    polls,
                };
                ['layer-a', 'layer-b'].forEach((id) => new MutationObserver((records) => { window.__loop.changes += records.length; })
                    .observe(document.getElementById(id), { attributes: true, attributeFilter: ['hidden'], childList: true }));
                [document.getElementById('stage'), document.body].forEach((node) => new MutationObserver((records) => {
                    records.forEach((record) => window.__loop.writes.push(record.attributeName));
                }).observe(node, { attributes: true }));
                document.addEventListener('play', (event) => {
                    if (event.target.closest('#layer-a, #layer-b')) window.__loop.starts += 1;
                }, true);
            JS);

            // Two more rounds of the three-second clip, and a poll — every thirty seconds — with the worker's answer
            // to the warm-up it asks for.
            $tv->waitUsing(20, 200, fn () => $tv->script('return window.__loop.starts >= 2;')[0], 'the clip did not come round again');
            $tv->waitUsing(40, 500, fn () => $tv->script('return window.__loop.polls() > window.__loop.pollsAtStart;')[0], 'no poll came');
            $tv->pause(3000);

            $loop = $tv->script(<<<JS
                const v = document.querySelector('{$onScreen}');
                return { same: v === window.__loop.first, changes: window.__loop.changes, writes: window.__loop.writes, videos: document.querySelectorAll('video').length, playing: !!v && !v.paused };
            JS)[0];

            $this->assertTrue($loop['same'], 'the clip was played again in a new element, so the set starts its decoder afresh every round');
            $this->assertSame(0, $loop['changes'], 'the layers were swapped for the same clip');
            $this->assertSame([], $loop['writes'], 'a poll that changed nothing wrote on the page: '.implode(', ', $loop['writes']));
            $this->assertSame(1, $loop['videos']);
            $this->assertTrue($loop['playing'], 'the clip stopped after coming round');

            $tv->visit('/login');
            $tv->script('localStorage.clear();');
        });
    }
}
