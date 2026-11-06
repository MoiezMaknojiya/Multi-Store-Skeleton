<?php

namespace Tests\Browser;

use App\Models\Campaign;
use App\Models\Media;
use App\Models\PlaylistItem;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Storage;
use Laravel\Dusk\Browser;
use Tests\Browser\Concerns\PlaysEverything;
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
    use DatabaseMigrations, PlaysEverything;

    protected function tearDown(): void
    {
        $this->forgetPageScripts();

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

            // The playlist changes while it plays: the library's picture goes and a new picture comes. The player asks
            // at once when the line comes back (the online event).
            $fresh = $this->picture($world['organization'], 'new-picture', [250, 140, 0]);
            PlaylistItem::where('media_id', $world['picture']->id)->delete();
            PlaylistItem::create(['screen_id' => $world['screen']->id, 'media_id' => $fresh->id, 'position' => 9, 'duration_seconds' => 10]);
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
}
