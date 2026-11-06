<?php

namespace Tests\Browser;

use App\Models\Media;
use App\Models\Organization;
use App\Models\PlaylistItem;
use App\Models\Screen;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * A video stopped before its first frame on a Fire TV (resources/js/player/fire-tv.js; the owner's Fire TV Stick,
 * 2026-11-06): its metadata and nothing more, no error and nothing loading, and the screen stayed empty for the video's
 * whole length and five seconds. The stop is played here by a page script: the first video says it has its metadata
 * alone, loads nothing, and its first frame is never heard. As a Fire TV it is set up once more and given up, and the
 * next item comes on within seconds; as an Android TV, whose player is as it was, it waits for its backstop.
 */
class StoppedFirstFrameTest extends DuskTestCase
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

    public function test_on_a_fire_tv_a_video_stopped_before_its_first_frame_is_set_up_again_then_given_up(): void
    {
        $this->screenWithAStoppedVideoFirst('stopped-fire-token');

        $this->browse(function (Browser $tv) {
            $this->playAs($tv, self::FIRE_TV);
            $this->stopTheFirstVideo($tv);
            $this->startTheSet($tv, 'stopped-fire-token');

            $tv->waitUntil('window.__stopped.next !== null', 20, 'the item after the stopped video never came on');
            $g = $tv->script('return window.__stopped;')[0];

            $this->assertLessThan(9000, $g['next'] - $g['started'], 'the next item came on '.round(($g['next'] - $g['started']) / 1000, 1).' s after the stopped video: '.json_encode($g));
            $this->assertSame(2, $g['metadata'], 'it was set up once more before it was given up: '.json_encode($g));
            $this->assertStringContainsString('fire-tv', implode(' ', $tv->script("return performance.getEntriesByType('resource').map((e) => e.name);")[0]));
        });
    }

    public function test_on_an_android_tv_the_player_is_as_it_was_and_waits_for_the_backstop(): void
    {
        $this->screenWithAStoppedVideoFirst('stopped-android-token');

        $this->browse(function (Browser $tv) {
            $this->playAs($tv, self::ANDROID_TV);
            $this->stopTheFirstVideo($tv);
            $this->startTheSet($tv, 'stopped-android-token');

            $tv->pause(15000);
            $g = $tv->script('return window.__stopped;')[0];

            $this->assertNotNull($g['started'], 'the stopped video was never made');
            $this->assertNull($g['next'], 'an Android TV gave the stopped video up: '.json_encode($g));
            $this->assertSame(1, $g['metadata'], 'an Android TV set the stopped video up again: '.json_encode($g));
            $this->assertStringNotContainsString('fire-tv', implode(' ', $tv->script("return performance.getEntriesByType('resource').map((e) => e.name);")[0]));
        });
    }

    /** A 4 s video the server says lasts 30 (its backstop 35 s), then a 2 s one. */
    private function screenWithAStoppedVideoFirst(string $token): void
    {
        $organization = Organization::factory()->create(['name' => 'Stick Mart']);
        $screen = Screen::factory()->withToken($token)->create([
            'organization_id' => $organization->id, 'name' => 'Stick TV', 'orientation' => 'landscape',
        ]);
        foreach ([['portrait-red-top.mp4', 30], ['portrait-red-top-2s.mp4', 2]] as $position => [$file, $seconds]) {
            $path = "media/{$organization->id}/".Str::ulid().'-'.$file;
            Storage::disk('public')->put($path, file_get_contents(base_path(self::FIXTURES.$file)));
            $media = Media::factory()->video()->create([
                'organization_id' => $organization->id, 'title' => $file, 'path' => $path, 'thumbnail_path' => null,
                'size' => filesize(base_path(self::FIXTURES.$file)), 'width' => 360, 'height' => 640, 'orientation' => 'portrait',
                'duration_seconds' => $seconds,
            ]);
            PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $media->id, 'position' => $position, 'duration_seconds' => null]);
        }
    }

    /**
     * Before the page loads: the first video made says it has its metadata alone (readyState 1) and loads nothing
     * (networkState 1, idle), and its first frame, its playing and its end are never heard. When it was made, how many
     * times its metadata came, and when the next video came on the glass.
     */
    private function stopTheFirstVideo(Browser $tv): void
    {
        $tools = new ChromeDevToolsDriver($tv->driver);
        $this->pageScripts[] = [$tools, (string) $tools->execute('Page.addScriptToEvaluateOnNewDocument', ['source' => <<<'JS'
            (() => {
                if (window.top !== window) return;
                const g = window.__stopped = { started: null, metadata: 0, next: null };
                let stopped = null;
                document.addEventListener('loadstart', (event) => {
                    const video = event.target;
                    if (video.tagName !== 'VIDEO' || stopped) return;
                    stopped = video;
                    g.started = performance.now();
                    Object.defineProperty(video, 'readyState', { configurable: true, get: () => 1 });
                    Object.defineProperty(video, 'networkState', { configurable: true, get: () => 1 });
                }, true);
                for (const type of ['loadeddata', 'canplay', 'canplaythrough', 'playing', 'timeupdate', 'ended']) {
                    window.addEventListener(type, (event) => { if (event.target === stopped) event.stopImmediatePropagation(); }, true);
                }
                window.addEventListener('loadedmetadata', (event) => { if (event.target === stopped) g.metadata += 1; }, true);
                document.addEventListener('DOMContentLoaded', () => {
                    for (const id of ['layer-a', 'layer-b']) {
                        new MutationObserver(() => {
                            const layer = document.getElementById(id);
                            const up = layer.firstElementChild;
                            if (g.next === null && stopped && !layer.hidden && up && up !== stopped && up.tagName === 'VIDEO') g.next = performance.now();
                        }).observe(document.getElementById(id), { attributes: true, attributeFilter: ['hidden'], childList: true });
                    }
                });
            })();
        JS])['identifier']];
    }

    private function startTheSet(Browser $tv, string $token): void
    {
        $tv->resize(1920, 1080);
        $tv->visit('/login');
        $tv->script("localStorage.clear(); localStorage.setItem('signage.device.token', '{$token}');");
        $tv->visit('/player');
    }
}
