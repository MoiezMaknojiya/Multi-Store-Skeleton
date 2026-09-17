<?php

namespace Tests\Browser;

use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\PlaylistItem;
use App\Models\Screen;
use App\Models\Store;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\File;
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

    /** A real PNG for the upload step. */
    private function fixtureImage(string $name, int $r, int $g, int $b): string
    {
        $directory = storage_path('framework/testing');
        File::ensureDirectoryExists($directory);
        $path = $directory.DIRECTORY_SEPARATOR.$name;

        $image = imagecreatetruecolor(640, 360);
        imagefilledrectangle($image, 0, 0, 640, 360, imagecolorallocate($image, $r, $g, $b));
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }

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

            // -- Its ads page: an image, with its seconds --------------------------
            $browser->visit('/channels/'.$channel->id);
            $this->waitForAlpine($browser);
            $browser->waitFor('@channel-ads-empty');

            $this->jsClick($browser, '@add-channel-ad');
            $browser->waitFor('@channel-ad-form')
                ->attach('@channel-ad-file', $this->fixtureImage('gama-coke.png', 200, 30, 30))
                ->assertVisible('@channel-ad-seconds');
            $this->jsType($browser, '@channel-ad-seconds', '12');
            $this->jsClick($browser, '@channel-ad-save');

            $browser->waitUsing(15, 200, fn () => ChannelAd::where('channel_id', $channel->id)->exists());
            $ad = ChannelAd::firstWhere('channel_id', $channel->id);
            $this->assertSame(12, $ad->duration_seconds);

            $browser->waitFor('@channel-ad-row-'.$ad->id)
                ->assertSeeIn('@channel-ad-title-'.$ad->id, 'gama-coke')
                ->assertSeeIn('@channel-ad-length-'.$ad->id, '12 secs')
                ->assertSeeIn('@channel-ad-status-'.$ad->id, 'Running')
                ->screenshot('channel-ads-page');

            // -- A video has no seconds to set --------------------------------------
            // A File is planted in the component rather than a real video uploaded:
            // the field disappearing is the point here, not a video's bytes.
            $this->jsClick($browser, '@add-channel-ad');
            $browser->waitFor('@channel-ad-form')->assertVisible('@channel-ad-seconds');
            $browser->script(<<<'JS'
                const root = document.querySelector('[x-data^="channelAds"]');
                Alpine.$data(root).selectedFile = new File([''], 'clip.mp4', { type: 'video/mp4' });
            JS);
            $browser->waitUntilMissing('@channel-ad-seconds')
                ->assertVisible('@channel-ad-video-note');
            $this->jsClick($browser, '@channel-ad-cancel');

            // -- Reordering ---------------------------------------------------------
            $second = ChannelAd::factory()->lasting(8)->create([
                'channel_id' => $channel->id, 'title' => 'Monster', 'position' => 99, 'thumbnail_path' => null,
            ]);

            $browser->visit('/channels/'.$channel->id);
            $this->waitForAlpine($browser);
            $browser->waitFor('@channel-ad-row-'.$second->id);
            $this->jsClick($browser, '@channel-ad-up-'.$second->id);
            $browser->waitUsing(10, 200, fn () => $second->fresh()->position === 0);

            // -- Deleting says how far the channel has spread, first ---------------
            $store = Store::factory()->create(['name' => 'Alpha Mart']);
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
