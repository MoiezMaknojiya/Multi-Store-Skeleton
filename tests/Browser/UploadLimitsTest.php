<?php

namespace Tests\Browser;

use App\Models\BuilderAsset;
use App\Models\Campaign;
use App\Models\Channel;
use App\Models\Media;
use App\Models\Store;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The two upload limits as a person meets them (owner, 2026-09-28): a video over five minutes, an advert over
 * sixty seconds and a file bigger than what is left of the shop's 512 MB are refused the moment they are chosen,
 * before a byte is sent — and the shop sees how full it is. The server decides either way
 * (tests/Feature/Signage/VideoLengthLimitTest, StoreStorageTest, tests/Feature/Security/UploadLimitsAttackTest);
 * these prove what the page says.
 *
 * The long videos are real ones, made in the page: WebCodecs VP8 at a frame a second, in a small WebM writer —
 * a browser measures them the way it measures a file off a phone.
 */
class UploadLimitsTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_the_media_page_says_how_full_the_shop_is_and_refuses_what_will_not_fit(): void
    {
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->storeMember($store);
        Media::factory()->create(['store_id' => $store->id, 'title' => 'Everything else', 'thumbnail_path' => null, 'size' => 510 * 1024 * 1024]);

        $this->browse(function (Browser $browser) use ($owner, $store) {
            $this->freshSession($browser);
            $browser->loginAs($owner);
            $this->switchToStore($browser, $store);
            $browser->visit('/media');
            $this->waitForAlpine($browser);

            $browser->waitFor('@storage-meter')
                ->assertSeeIn('@storage-meter-text', '510 MB of 512 MB used')
                ->assertSeeIn('@storage-meter-warning', 'Almost full.');

            $this->jsClick($browser, '@upload-media');
            $browser->waitFor('@media-upload-form');

            // Three megabytes, with two left: refused as it is chosen.
            $this->choose($browser, 'media-file', "new File([new Uint8Array(3 * 1024 * 1024)], 'big.jpg', { type: 'image/jpeg' })");
            $browser->waitForText('Not enough storage: this needs 3 MB, and this shop has 2 MB left of its 512 MB.');

            // A six-minute video: refused as it is chosen, in the server's own words.
            $this->choose($browser, 'media-file', "window.__makeVideo(360, 'long.webm')");
            $browser->waitForText('A video may be at most 5 minutes long. This one is 6:00.', 30);
            $this->assertSame(1, Media::count(), 'nothing was uploaded');

            // A picture that fits goes up at once, and the meter moves with it.
            $this->choose($browser, 'media-file', "new Promise((resolve) => { const c = document.createElement('canvas'); c.width = 64; c.height = 64; c.toBlob((blob) => resolve(new File([blob], 'fits.png', { type: 'image/png' })), 'image/png'); })");
            $browser->waitUsing(20, 200, fn () => Media::count() === 2)
                ->waitUsing(10, 200, fn () => $browser->text('@storage-meter-text') !== '510 MB of 512 MB used');
            $this->assertMatchesRegularExpression('/^510\.\d MB of 512 MB used$/', $browser->text('@storage-meter-text'));
        });
    }

    public function test_a_channel_upload_refuses_a_video_over_five_minutes_and_the_ad_builder_shelf_one_over_thirty_seconds(): void
    {
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        // Channels are the platform's permissions a store's role may carry: given here, as a shop would give them.
        $owner = $this->storeMember($store, ['channel-view', 'channel-update', 'ad-view', 'ad-store', 'media-view', 'media-store']);
        $channel = Channel::factory()->create(['store_id' => $store->id, 'name' => 'GHRA Ware House']);

        $this->browse(function (Browser $browser) use ($owner, $store, $channel) {
            $this->freshSession($browser);
            $browser->loginAs($owner);
            $this->switchToStore($browser, $store);

            $browser->visit("/channels/{$channel->id}");
            $this->waitForAlpine($browser);
            $this->jsClick($browser, '@add-channel-ad');
            $browser->waitFor('@channel-ad-form');
            $this->jsClick($browser, '@channel-ad-source-upload');
            $browser->waitFor('@channel-ad-drop');
            $this->choose($browser, 'channel-ad-file', "window.__makeVideo(420, 'long.webm')");
            $browser->waitForText('A video may be at most 5 minutes long. This one is 7:00.', 30);

            $browser->visit('/builder/assets');
            $this->waitForAlpine($browser);
            $browser->waitFor('@storage-meter')->assertSeeIn('@storage-meter-text', '0 KB of 512 MB used');
            // The shelf keeps thirty seconds: a design's video repeats for as long as the ad is up.
            $this->choose($browser, 'asset-file', "window.__makeVideo(45, 'long.webm')");
            $browser->waitForText('A video may be at most 30 seconds long. This one is 0:45.', 30);

            $this->assertSame(0, Media::count() + BuilderAsset::count(), 'nothing was uploaded');
        });
    }

    public function test_an_advert_is_sixty_seconds_at_most(): void
    {
        $admin = $this->seedSuperAdmin();

        $this->browse(function (Browser $browser) use ($admin) {
            $this->freshSession($browser);
            $browser->loginAs($admin);
            $browser->visit('/campaigns');
            $this->waitForAlpine($browser);

            $this->jsClick($browser, '@add-campaign');
            $browser->waitFor('@campaign-form');
            $this->choose($browser, 'campaign-file', "window.__makeVideo(70, 'advert.webm')");
            $browser->waitForText('An advert may be at most 60 seconds long. This one is 1:10.', 30);

            // A picture's seconds, one past a break — once the picture has gone up, since Save waits for it.
            $this->choose($browser, 'campaign-file', "new Promise((resolve) => { const c = document.createElement('canvas'); c.width = 64; c.height = 64; c.toBlob((blob) => resolve(new File([blob], 'advert.png', { type: 'image/png' })), 'image/png'); })");
            $browser->waitForTextIn('@campaign-upload-status', 'Uploaded', 20);
            $browser->type('@campaign-name', 'Coca-Cola')->clear('@campaign-seconds')->type('@campaign-seconds', '61');
            $this->jsClick($browser, '@campaign-save');
            $browser->waitForText('An advert stays on screen for at most 60 seconds: one break.');

            $this->assertSame(0, Campaign::count(), 'nothing was saved');
        });
    }

    /** Put a file made in the page into a file input, as choosing one does. */
    private function choose(Browser $browser, string $input, string $makeFile): void
    {
        $this->defineMakeVideo($browser);
        $browser->script(<<<JS
            window.__chosen = false;
            Promise.resolve({$makeFile}).then((file) => {
                const input = document.querySelector('[dusk="{$input}"]');
                const list = new DataTransfer();
                list.items.add(file);
                input.files = list.files;
                input.dispatchEvent(new Event('change', { bubbles: true }));
                window.__chosen = true;
            });
        JS);
        $browser->waitUsing(60, 200, fn () => $browser->script('return window.__chosen;')[0] === true);
    }
}
