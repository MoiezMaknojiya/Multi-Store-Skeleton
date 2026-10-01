<?php

namespace Tests\Browser;

use App\Models\BuilderAd;
use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Media;
use App\Models\Organization;
use App\Models\PlaylistItem;
use App\Models\Screen;
use App\Models\User;
use App\Services\AdPublisher;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Storage;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * An organization putting a channel on a screen, and the screen playing it.
 *
 * The part worth proving in a real browser is the television: that a channel line
 * plays exactly where it stands, that a channel playing one ad per pass takes the NEXT
 * ad on the next pass, and that nothing piles up in the layers while it rotates — a set
 * left running for weeks must look the same on day thirty as on day one.
 */
class PlaylistChannelUiTest extends DuskTestCase
{
    use DatabaseMigrations;

    /** An organization owner who builds their own playlists. */
    private function owner(Organization $organization): User
    {
        $this->seedSuperAdmin();

        return $this->organizationMember($organization, ['screen-view', 'screen-playlist']);
    }

    public function test_a_organization_owner_adds_a_channel_and_puts_it_where_they_want(): void
    {
        $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->owner($organization);
        $screen = Screen::factory()->create(['organization_id' => $organization->id, 'name' => 'Counter TV']);

        $poster = Media::factory()->create(['organization_id' => $organization->id, 'title' => 'Burger deal', 'thumbnail_path' => null]);
        PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $poster->id, 'position' => 0, 'duration_seconds' => 10]);

        $gama = Channel::factory()->perPass(1)->create(['name' => 'GAMA']);
        ChannelAd::factory()->lasting(10)->showing(['thumbnail_path' => null])->create(['channel_id' => $gama->id, 'title' => 'Monster', 'position' => 0]);
        ChannelAd::factory()->lasting(15)->showing(['thumbnail_path' => null])->create(['channel_id' => $gama->id, 'title' => 'Coke', 'position' => 1]);

        $this->browse(function (Browser $browser) use ($owner, $organization, $screen, $gama) {
            $this->freshSession($browser);
            $browser->loginAs($owner);
            $this->switchToOrganization($browser, $organization);

            $browser->visit('/screens/'.$screen->id);
            $this->waitForAlpine($browser);

            // -- The box, under the library, says what the channel will play --------
            $browser->waitFor('@channel-picker')
                ->assertSeeIn('@channel-picker-info-'.$gama->id, '2 ads · 1 each time, about 10 secs');

            $this->jsClick($browser, '@channel-preview-'.$gama->id);
            $browser->waitFor('@channel-ads-preview-'.$gama->id)
                ->assertSeeIn('@channel-ads-preview-'.$gama->id, 'Monster')
                ->assertSeeIn('@channel-ads-preview-'.$gama->id, 'Coke');

            // -- Add it: one line, at the end, with no length to type ----------------
            $this->jsClick($browser, '@playlist-add-channel-'.$gama->id);
            $browser->waitFor('@playlist-channel-info-1')
                ->assertSeeIn('@playlist-length-1', '~10 secs')
                ->assertMissing('@playlist-duration-1')
                ->screenshot('playlist-with-channel');

            // -- Move it to the top, save, and it stays there -------------------------
            $this->jsClick($browser, '@playlist-up-1');
            $browser->waitFor('@playlist-channel-info-0');

            // The move has to reach the LIST, not only the picture: what is saved is the
            // array, and a swap that only redrew would save the old order.
            $this->assertSame(['channel', 'image'], $browser->script(
                'return Alpine.$data(document.querySelector(\'[x-data^="screenPlaylist"]\')).items.map(i => i.type);'
            )[0]);

            $this->jsClick($browser, '@playlist-save');

            // Wait for the page's own "saved" signal — `dirty` going false, which happens
            // only after the server has taken the list. NOT the button's disabled state:
            // that is also true while the request is still in flight. If it never comes,
            // the panel's own toast says why, so report that rather than "timeout".
            try {
                $browser->waitUsing(15, 250, fn () => $browser->script(
                    'return Alpine.$data(document.querySelector(\'[x-data^="screenPlaylist"]\')).dirty === false;'
                )[0] === true);
            } catch (\Throwable $e) {
                $this->fail('The playlist did not save: '.$browser->script(
                    'return (Alpine.store("toasts").items[0] || {}).message || "(no message shown)";'
                )[0]);
            }

            // What the server sent back, and what it actually stored — reported together,
            // so a disagreement between the two says which side is wrong.
            $afterSave = $browser->script(
                'return Alpine.$data(document.querySelector(\'[x-data^="screenPlaylist"]\')).items.map(i => i.type);'
            )[0];

            $rows = PlaylistItem::where('screen_id', $screen->id)->orderBy('position')
                ->get(['id', 'position', 'media_id', 'channel_id'])->toArray();

            $this->assertSame(['channel', 'image'], $afterSave, 'stored rows: '.json_encode($rows));
            $this->assertSame($gama->id, $rows[0]['channel_id'] ?? null, 'stored rows: '.json_encode($rows));

            $browser->visit('/screens/'.$screen->id);
            $this->waitForAlpine($browser);
            $browser->waitFor('@playlist-channel-info-0')
                ->assertSeeIn('@playlist-summary', '2 items');
        });
    }

    /**
     * On the television: Burger, Monster, Burger, Coke, Burger, Monster.
     *
     * The channel sits second and plays ONE ad each time round, so every pass has to take
     * the next ad — and go back to the first once they have all been shown.
     */
    public function test_the_tv_plays_the_channel_in_place_and_rotates_its_ads(): void
    {
        $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
        $screen = Screen::factory()->withToken('channel-token')->create(['organization_id' => $organization->id, 'name' => 'Counter TV']);

        $poster = Media::factory()->create([
            'organization_id' => $organization->id, 'title' => 'Burger', 'mime_type' => 'image/png', 'thumbnail_path' => null,
            'path' => $this->putImage("media/{$organization->id}/burger.png", 200, 120, 30),
        ]);
        PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $poster->id, 'position' => 0, 'duration_seconds' => 6]);

        // The platform's channel shows two files of the platform's own library.
        $gama = Channel::factory()->perPass(1)->create(['name' => 'GAMA']);
        ChannelAd::factory()->lasting(6)->showing([
            'mime_type' => 'image/png', 'thumbnail_path' => null, 'path' => $this->putImage('media/platform/monster.png', 30, 200, 60),
        ])->create(['channel_id' => $gama->id, 'title' => 'Monster', 'position' => 0]);
        ChannelAd::factory()->lasting(6)->showing([
            'mime_type' => 'image/png', 'thumbnail_path' => null, 'path' => $this->putImage('media/platform/coke.png', 220, 30, 40),
        ])->create(['channel_id' => $gama->id, 'title' => 'Coke', 'position' => 1]);
        PlaylistItem::create(['screen_id' => $screen->id, 'channel_id' => $gama->id, 'position' => 1]);

        $this->browse(function (Browser $tv) {
            // Plant the token from a page that is NOT the player (see PlaylistFlowTest).
            $tv->visit('/login');
            $tv->script("localStorage.clear(); localStorage.setItem('signage.device.token', 'channel-token');");
            $tv->visit('/player');

            $visible = '#layer-a:not([hidden]) img, #layer-b:not([hidden]) img';
            $tv->waitUntil("!!document.querySelector('{$visible}')", 30);

            // Read the wall as it changes: one name each time the picture does — five changes of six seconds each.
            $seen = [];
            $tv->waitUsing(60, 100, function () use ($tv, $visible, &$seen) {
                $src = $tv->script("return (document.querySelector('{$visible}') || {}).src || '';")[0];
                $name = basename((string) parse_url($src, PHP_URL_PATH));

                if ($name !== '' && end($seen) !== $name) {
                    $seen[] = $name;
                }

                return count($seen) >= 6;
            });

            $this->assertSame(
                ['burger.png', 'monster.png', 'burger.png', 'coke.png', 'burger.png', 'monster.png'],
                array_slice($seen, 0, 6)
            );

            // Only ever one picture per layer: nothing piles up as the ads rotate.
            $this->assertLessThanOrEqual(2, $tv->script('return document.querySelectorAll("#layer-a img, #layer-b img").length;')[0]);

            // Leave the player, so its timers do not follow this browser into the next test.
            $tv->visit('/login');
            $tv->script('localStorage.clear();');
        });
    }

    /**
     * docs/CHANNEL-CONTENT-SPEC.md §7: a channel carrying a picture and a published Ad Builder ad plays both in
     * turn — the ad in a frame of its own — and the ad published again while the set runs reaches the screen
     * on its next poll, because the channel holds the library row and not a copy of it.
     */
    public function test_a_channel_plays_a_picture_and_an_ad_page_and_a_republished_ad_reaches_the_screen(): void
    {
        $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
        $screen = Screen::factory()->withToken('channel-page-token')->create(['organization_id' => $organization->id, 'name' => 'Counter TV']);

        $poster = Media::factory()->create([
            'organization_id' => $organization->id, 'title' => 'Burger', 'mime_type' => 'image/png', 'thumbnail_path' => null,
            'path' => $this->putImage("media/{$organization->id}/burger.png", 200, 120, 30),
        ]);
        PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $poster->id, 'position' => 0, 'duration_seconds' => 6]);

        // The organization's own channel: a picture of its library, then an ad its Ad Builder published into that library.
        $deals = Channel::factory()->create(['name' => 'Alpha Deals', 'organization_id' => $organization->id]);
        ChannelAd::factory()->lasting(6)->showing([
            'mime_type' => 'image/png', 'thumbnail_path' => null, 'path' => $this->putImage("media/{$organization->id}/monster.png", 30, 200, 60),
        ])->create(['channel_id' => $deals->id, 'title' => 'Monster', 'position' => 0]);

        $design = BuilderAd::factory()->withText('Winter sale')->create(['organization_id' => $organization->id, 'name' => 'Winter sale']);
        $page = app(AdPublisher::class)->publish($design);
        // The page plays for its own length (six seconds, its design's default), so the ad keeps no seconds.
        ChannelAd::factory()->create(['channel_id' => $deals->id, 'media_id' => $page->id, 'title' => 'Winter sale', 'position' => 1, 'duration_seconds' => null]);
        PlaylistItem::create(['screen_id' => $screen->id, 'channel_id' => $deals->id, 'position' => 1]);

        $this->browse(function (Browser $tv) use ($design, $page) {
            $tv->visit('/login');
            $tv->script("localStorage.clear(); localStorage.setItem('signage.device.token', 'channel-page-token');");
            $tv->visit('/player');

            // What the wall shows right now: a picture by its file name, or the ad page by its address (the
            // frame opens the page inside the worker's scope when a worker keeps the set — docs §15 — and says
            // which page it holds either way).
            $showing = fn (): string => (string) $tv->script(<<<'JS'
                const frame = document.querySelector('#layer-a:not([hidden]) iframe, #layer-b:not([hidden]) iframe');
                if (frame) return 'page ' + (frame.dataset.src || '');
                const picture = document.querySelector('#layer-a:not([hidden]) img, #layer-b:not([hidden]) img');
                // A file's address carries its cache key (?c=…); the name is what the test knows it by.
                return picture ? 'picture ' + picture.src.split('/').pop().split('?')[0] : '';
            JS)[0];

            // -- In turn: the organization's poster, the channel's picture, the channel's ad page ------
            $seen = [];
            $tv->waitUsing(45, 100, function () use ($showing, &$seen) {
                $now = $showing();
                if ($now !== '' && end($seen) !== $now) {
                    $seen[] = $now;
                }

                return count($seen) >= 4;
            });

            $firstVersion = '?v='.$page->updated_at->getTimestamp();
            $this->assertSame('picture burger.png', $seen[0] ?? null, 'seen: '.json_encode($seen));
            $this->assertSame('picture monster.png', $seen[1] ?? null, 'seen: '.json_encode($seen));
            $this->assertStringStartsWith('page ', $seen[2] ?? '', 'seen: '.json_encode($seen));
            $this->assertStringContainsString($firstVersion, $seen[2], 'the frame is not showing the published page');
            $this->assertSame('picture burger.png', $seen[3] ?? null, 'seen: '.json_encode($seen));

            // -- Published again while the set runs: the next poll brings the new page --------
            $document = $design->fresh()->document;
            $document['elements'][0]['text'] = 'Spring sale';
            $design->forceFill(['document' => $document])->save();
            $republished = app(AdPublisher::class)->publish($design->fresh());

            $this->assertSame($page->id, $republished->id, 'publishing again made a second library row');
            $newVersion = '?v='.$republished->updated_at->getTimestamp();
            $this->assertNotSame($firstVersion, $newVersion);
            $this->assertStringContainsString('Spring sale', (string) Storage::disk('public')->get($republished->path));

            // The playlist poll is every 30 seconds, and the page is up for 6 seconds of every 18.
            $tv->waitUsing(80, 150, fn () => str_contains($showing(), $newVersion));

            // Leave the player, so its timers do not follow this browser into the next test.
            $tv->visit('/login');
            $tv->script('localStorage.clear();');
        });
    }
}
