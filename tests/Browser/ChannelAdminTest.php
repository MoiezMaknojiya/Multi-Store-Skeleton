<?php

namespace Tests\Browser;

use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Media;
use App\Models\PlaylistItem;
use App\Models\Screen;
use App\Models\Store;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Managing channels through the real pages, as the super admin.
 *
 * The backend tests prove the rules; this proves the pages can drive them — that the
 * modal saves, that an ad uploads through the file input, that the seconds field really
 * disappears for a video, and that the delete confirmation shows the true reach before
 * anybody presses the button.
 */
class ChannelAdminTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_the_super_admin_builds_a_channel_fills_it_and_deletes_it(): void
    {
        $admin = $this->seedSuperAdmin();

        $this->browse(function (Browser $browser) use ($admin) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/channels');
            $this->waitForAlpine($browser);

            // -- Create the channel ------------------------------------------------
            $this->jsClick($browser, '@add-channel');
            $browser->waitFor('@channel-form');
            $this->jsType($browser, '@channel-name', 'GAMA Wholesale');
            $this->jsType($browser, '@channel-ads-per-pass', '2');
            $this->jsClick($browser, '@channel-save');

            $browser->waitUsing(10, 200, fn () => Channel::where('name', 'GAMA Wholesale')->exists());
            $channel = Channel::firstWhere('name', 'GAMA Wholesale');
            $this->assertSame(2, $channel->ads_per_pass);

            $browser->waitFor('@channel-name-'.$channel->id)
                ->assertSeeIn('@channel-ads-'.$channel->id, 'No ads yet');

            // A shop whose Ad Builder has published an ad: the platform's channel may show a shop's file.
            $store = Store::factory()->create(['name' => 'Alpha Mart']);
            $page = Media::factory()->adPage()->create(['store_id' => $store->id, 'title' => 'Burger Deal', 'thumbnail_path' => null]);

            // -- Its ads page: an upload, which joins the platform's library first --
            $browser->visit('/channels/'.$channel->id);
            $this->waitForAlpine($browser);
            $browser->waitFor('@channel-ads-empty');

            // A new ad starts from the library — the platform's, empty so far.
            $this->jsClick($browser, '@add-channel-ad');
            $browser->waitFor('@channel-ad-form')
                ->waitFor('@channel-ad-picker-empty')
                ->assertSeeIn('@channel-ad-picker-empty', 'Nothing in this library yet');

            $this->jsClick($browser, '@channel-ad-source-upload');
            $browser->waitFor('@channel-ad-file')
                ->assertSeeIn('@channel-ad-upload-note', "It joins the platform's media library")
                ->attach('@channel-ad-file', $this->fixtureImage('gama-coke.png', 200, 30, 30))
                ->assertVisible('@channel-ad-seconds');
            $this->jsType($browser, '@channel-ad-seconds', '12');
            $this->jsClick($browser, '@channel-ad-save');

            $browser->waitUsing(15, 200, fn () => ChannelAd::where('channel_id', $channel->id)->exists());
            $ad = ChannelAd::firstWhere('channel_id', $channel->id);
            $this->assertSame(12, $ad->duration_seconds);
            $this->assertNull($ad->media->store_id, "the upload did not join the platform's library");
            $this->assertStringStartsWith('media/platform/', $ad->media->path);

            $browser->waitFor('@channel-ad-row-'.$ad->id)
                ->assertSeeIn('@channel-ad-title-'.$ad->id, 'gama-coke')
                ->assertSeeIn('@channel-ad-length-'.$ad->id, '12 secs')
                ->assertSeeIn('@channel-ad-status-'.$ad->id, 'Running')
                ->assertSeeIn('@channel-ad-library-'.$ad->id, 'Platform')
                ->screenshot('channel-ads-page');

            // -- The same file again, chosen from the library: nothing uploaded twice --
            $this->jsClick($browser, '@add-channel-ad');
            $browser->waitFor('@channel-ad-pick-'.$ad->media_id)->screenshot('channel-ad-library-picker');
            $this->jsClick($browser, '@channel-ad-pick-'.$ad->media_id);
            $this->jsType($browser, '@channel-ad-title', 'Coke again');
            $this->jsType($browser, '@channel-ad-seconds', '7');
            $this->jsClick($browser, '@channel-ad-save');

            $browser->waitUsing(15, 200, fn () => ChannelAd::where('channel_id', $channel->id)->count() === 2);
            $again = ChannelAd::where('channel_id', $channel->id)->latest('id')->first();
            $this->assertSame($ad->media_id, $again->media_id);
            $this->assertSame(7, $again->duration_seconds);
            $this->assertSame(1, Media::whereNull('store_id')->count(), 'choosing a library file made a copy of it');
            $browser->waitFor('@channel-ad-row-'.$again->id)->assertSeeIn('@channel-ad-title-'.$again->id, 'Coke again');

            // -- An Ad Builder ad: the platform's own library has none, so a shop is chosen --
            $this->jsClick($browser, '@add-channel-ad');
            $browser->waitFor('@channel-ad-form');
            $this->jsClick($browser, '@channel-ad-source-ads');
            $browser->waitFor('@channel-ad-picker-empty')
                ->assertSeeIn('@channel-ad-picker-empty', 'choose the shop above')
                ->select('@channel-ad-library', (string) $store->id)
                ->waitFor('@channel-ad-pick-'.$page->id);
            $this->jsClick($browser, '@channel-ad-pick-'.$page->id);
            $browser->assertVisible('@channel-ad-seconds');
            $this->jsType($browser, '@channel-ad-seconds', '15');
            $this->jsClick($browser, '@channel-ad-save');

            $browser->waitUsing(15, 200, fn () => ChannelAd::where('channel_id', $channel->id)->where('media_id', $page->id)->exists());
            $burger = ChannelAd::where('channel_id', $channel->id)->where('media_id', $page->id)->first();
            $this->assertSame(15, $burger->duration_seconds);
            $browser->waitFor('@channel-ad-row-'.$burger->id)
                ->assertSeeIn('@channel-ad-title-'.$burger->id, 'Burger Deal')
                ->assertSeeIn('@channel-ad-row-'.$burger->id, 'Ad page')
                ->assertSeeIn('@channel-ad-library-'.$burger->id, 'Alpha Mart');

            // -- A video has no seconds to set --------------------------------------
            // A File is planted in the component rather than a real video uploaded:
            // the field disappearing is the point here, not a video's bytes.
            $this->jsClick($browser, '@add-channel-ad');
            $browser->waitFor('@channel-ad-form')->assertVisible('@channel-ad-seconds');
            $this->jsClick($browser, '@channel-ad-source-upload');
            $browser->waitFor('@channel-ad-file');
            $browser->script(<<<'JS'
                const root = document.querySelector('[x-data^="channelAds"]');
                Alpine.$data(root).selectedFile = new File([''], 'clip.mp4', { type: 'video/mp4' });
            JS);
            $browser->waitUntilMissing('@channel-ad-seconds')
                ->assertVisible('@channel-ad-video-note');
            $this->jsClick($browser, '@channel-ad-cancel');

            // -- Reordering ---------------------------------------------------------
            $second = ChannelAd::factory()->lasting(8)->showing(['thumbnail_path' => null])->create([
                'channel_id' => $channel->id, 'title' => 'Monster', 'position' => 99,
            ]);

            $browser->visit('/channels/'.$channel->id);
            $this->waitForAlpine($browser);
            $browser->waitFor('@channel-ad-row-'.$second->id);
            // Fourth of four; one step up puts it third.
            $this->jsClick($browser, '@channel-ad-up-'.$second->id);
            $browser->waitUsing(10, 200, fn () => $second->fresh()->position === 2);

            // -- Deleting says how far the channel has spread, first ---------------
            $screen = Screen::factory()->create(['store_id' => $store->id]);
            PlaylistItem::create(['screen_id' => $screen->id, 'channel_id' => $channel->id, 'position' => 0]);

            $browser->visit('/channels');
            $this->waitForAlpine($browser);
            $browser->waitFor('@delete-channel-'.$channel->id)
                ->assertSeeIn('@channel-usage-'.$channel->id, '1 screen in 1 shop');

            $this->jsClick($browser, '@delete-channel-'.$channel->id);
            $browser->waitFor('@channel-delete-usage')
                ->assertSeeIn('@channel-delete-usage', '1 screen in 1 shop')
                ->pause(400)
                ->screenshot('channel-delete-confirm');
            // A big delete asks for the password — the seeded admin's is SEED_ADMIN_PASSWORD (phpunit.dusk.xml).
            $this->jsType($browser, '@confirm-channel-deletion-password', 'test');
            $this->jsClick($browser, '@confirm-channel-deletion-confirm');

            $browser->waitUsing(10, 200, fn () => ! Channel::where('id', $channel->id)->exists());
            $this->assertSame(0, PlaylistItem::where('channel_id', $channel->id)->count());
            // Its ads went; their files stay in their libraries.
            $this->assertSame(0, ChannelAd::where('channel_id', $channel->id)->count());
            $this->assertNotNull(Media::find($ad->media_id));
            $this->assertNotNull(Media::find($page->id));

            // The page reloads its list after a delete. Ending the test while that request is
            // still out would have it answered by a database already torn down — a 500 that
            // lands in the NEXT test's console log. The empty list is that request come back.
            $browser->waitForText('No channels yet.');
        });
    }

    /**
     * Inside a shop, the platform's channel is listed and opened to look at (owner, 2026-09-19) — nothing on
     * it offers a change — while the shop's own channel keeps every button.
     */
    public function test_inside_a_shop_the_platforms_channel_is_there_to_look_at(): void
    {
        $this->seedSuperAdmin();
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $keeper = $this->storeMember($store, ['channel-view', 'channel-store', 'channel-update', 'channel-destroy']);

        $gama = Channel::factory()->create(['name' => 'GAMA']);
        $promo = ChannelAd::factory()->lasting(10)->showing(['thumbnail_path' => null])->create(['channel_id' => $gama->id, 'title' => 'Monster']);
        $own = Channel::factory()->create(['name' => 'Alpha Specials', 'store_id' => $store->id]);

        $this->browse(function (Browser $browser) use ($keeper, $store, $gama, $promo, $own) {
            $this->freshSession($browser);
            $browser->loginAs($keeper);
            $this->switchToStore($browser, $store);

            $browser->visit('/channels');
            $this->waitForAlpine($browser);
            $browser->waitFor('@channel-name-'.$gama->id)
                ->assertVisible('@channel-from-platform-'.$gama->id)
                ->assertMissing('@edit-channel-'.$gama->id)
                ->assertMissing('@delete-channel-'.$gama->id)
                ->assertVisible('@channel-open-'.$gama->id)
                ->assertVisible('@edit-channel-'.$own->id)
                ->assertVisible('@delete-channel-'.$own->id)
                ->assertMissing('@channel-from-platform-'.$own->id)
                ->screenshot('channels-inside-a-shop');

            $browser->visit('/channels/'.$gama->id);
            $this->waitForAlpine($browser);
            $browser->waitFor('@channel-ad-row-'.$promo->id)
                ->assertSeeIn('@channel-ad-title-'.$promo->id, 'Monster')
                ->assertVisible('@channel-read-only-note')
                ->assertMissing('@add-channel-ad')
                ->assertMissing('@edit-channel-ad-'.$promo->id)
                ->assertMissing('@remove-channel-ad-'.$promo->id)
                ->assertMissing('@channel-ad-up-'.$promo->id);
        });
    }

    /** The campaign form follows the same rule: a video has no seconds field at all. */
    public function test_the_campaign_form_has_no_seconds_for_a_video(): void
    {
        $admin = $this->seedSuperAdmin();

        $this->browse(function (Browser $browser) use ($admin) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/campaigns');
            $this->waitForAlpine($browser);

            $this->jsClick($browser, '@add-campaign');
            $browser->waitFor('@campaign-form')->assertVisible('@campaign-seconds');

            $browser->script(<<<'JS'
                const root = document.querySelector('[x-data^="campaignsTable"]');
                Alpine.$data(root).selectedFile = new File([''], 'coke.mp4', { type: 'video/mp4' });
            JS);

            $browser->waitUntilMissing('@campaign-seconds')
                ->assertVisible('@campaign-video-note');
        });
    }
}
