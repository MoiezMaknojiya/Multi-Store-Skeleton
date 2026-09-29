<?php

namespace Tests\Browser;

use App\Models\Campaign;
use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Media;
use App\Models\Store;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * A date or a time typed only in part (the owner's brute-force round, 2026-09-29). The browser hands such a field over
 * as empty; with the forms saying their own errors (novalidate — never the browser's bubble), it was saved as "no
 * date": a channel ad's end date gone, an advert's hours gone. Now it is said under the field, and nothing is sent.
 */
class HalfTypedDatesTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_a_half_typed_end_date_on_a_channel_ad_is_said_under_it_and_nothing_is_saved(): void
    {
        $this->seedSuperAdmin();
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $keeper = $this->storeMember($store, ['channel-view', 'channel-store', 'channel-update'], 'keeper@example.com', 'Keeper');
        $channel = Channel::factory()->create(['store_id' => $store->id, 'name' => 'Our Deals']);
        $picture = Media::factory()->create(['store_id' => $store->id, 'title' => 'Deal poster', 'thumbnail_path' => null]);

        $this->browse(function (Browser $browser) use ($keeper, $store, $channel, $picture) {
            $this->freshSession($browser);
            $browser->loginAs($keeper);
            $this->switchToStore($browser, $store);
            $browser->visit('/channels/'.$channel->id);
            $this->waitForAlpine($browser);

            $this->jsClick($browser, '@add-channel-ad');
            $browser->waitFor('@channel-ad-pick-'.$picture->id);
            $this->jsClick($browser, '@channel-ad-pick-'.$picture->id);
            $this->jsType($browser, '@channel-ad-seconds', '8');

            // Only the month of the end date.
            $browser->click('@channel-ad-ends-on')->keys('@channel-ad-ends-on', '12');
            $this->assertTrue($browser->script('return document.querySelector(\'[dusk="channel-ad-ends-on"]\').validity.badInput;')[0],
                'the browser could not read the date');

            $this->jsClick($browser, '@channel-ad-save');
            $browser->waitForText('Enter the whole date, or leave it blank.');
            $this->assertSame(0, ChannelAd::count(), 'nothing was saved');
        });
    }

    public function test_a_half_typed_time_on_an_advert_is_said_under_it_and_nothing_is_saved(): void
    {
        $admin = $this->seedSuperAdmin();

        $this->browse(function (Browser $browser) use ($admin) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/campaigns');
            $this->waitForAlpine($browser);

            $this->clickAndAwait($browser, '@add-campaign', fn (Browser $b) => $b->waitFor('@campaign-form', 3));
            $this->jsType($browser, '@campaign-name', 'Winter Cola');
            $this->uploadThrough($browser, 'campaign', $this->fixtureImage('advert-half-time.png', 20, 90, 200));
            $browser->waitFor('@campaign-seconds');
            $this->jsType($browser, '@campaign-seconds', '10');

            // Only the hour of the start time.
            $browser->click('@campaign-start-time')->keys('@campaign-start-time', '11');
            $this->assertTrue($browser->script('return document.querySelector(\'[dusk="campaign-start-time"]\').validity.badInput;')[0],
                'the browser could not read the time');

            $this->jsClick($browser, '@campaign-save');
            $browser->waitForText('Enter the whole time, or leave it blank.');
            $this->assertFalse(Campaign::where('name', 'Winter Cola')->exists(), 'nothing was saved');
        });
    }
}
