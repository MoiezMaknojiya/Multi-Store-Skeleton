<?php

namespace Tests\Browser;

use App\Models\Campaign;
use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Media;
use App\Models\Organization;
use App\Models\PlaylistItem;
use App\Models\Role;
use App\Models\Screen;
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
        $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
        $keeper = $this->organizationMember($organization, ['channel-view', 'channel-store', 'channel-update'], 'keeper@example.com', 'Keeper');
        $channel = Channel::factory()->create(['organization_id' => $organization->id, 'name' => 'Our Deals']);
        $picture = Media::factory()->create(['organization_id' => $organization->id, 'title' => 'Deal poster', 'thumbnail_path' => null]);

        $this->browse(function (Browser $browser) use ($keeper, $organization, $channel, $picture) {
            $this->freshSession($browser);
            $browser->loginAs($keeper);
            $this->switchToOrganization($browser, $organization);
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

    /**
     * The hours of a schedule are typed on the rule itself since 2026-10-01: a start typed only as its hour reads as no
     * start at all, so OK says it under the rule and stages nothing — the line keeps playing all day otherwise.
     */
    public function test_a_half_typed_time_on_a_schedule_is_said_under_its_rule_and_nothing_is_staged(): void
    {
        $this->seedSuperAdmin();
        $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->organizationMember($organization, Role::OWNER);
        $screen = Screen::factory()->create(['organization_id' => $organization->id, 'name' => 'Deli TV']);
        PlaylistItem::create([
            'screen_id' => $screen->id, 'position' => 0, 'duration_seconds' => 10,
            'media_id' => Media::factory()->create(['organization_id' => $organization->id, 'title' => 'Eid offer', 'thumbnail_path' => null])->id,
        ]);

        $this->browse(function (Browser $browser) use ($owner, $organization, $screen) {
            $this->freshSession($browser);
            $browser->loginAs($owner);
            $this->switchToOrganization($browser, $organization);
            $browser->visit('/screens/'.$screen->id);
            $this->waitForAlpine($browser);
            $browser->waitFor('@playlist-schedule-0');

            $this->clickAndAwait($browser, '@playlist-schedule-0', fn (Browser $b) => $b->waitFor('@schedule-modal'));
            $this->clickAndAwait($browser, '@schedule-add-rule', fn (Browser $b) => $b->waitFor('@rule-day-mode-0'));
            $browser->select('@rule-time-mode-0', 'times')->waitFor('@rule-start-time-0');

            // Only the hour of the start; the end whole.
            $browser->click('@rule-start-time-0')->keys('@rule-start-time-0', '11');
            $browser->script(
                'const end = document.querySelector(\'[dusk="rule-end-time-0"]\'); end.value = "15:00";'
                .'end.dispatchEvent(new Event("input", { bubbles: true })); end.dispatchEvent(new Event("change", { bubbles: true }));'
            );
            $this->assertTrue($browser->script('return document.querySelector(\'[dusk="rule-start-time-0"]\').validity.badInput;')[0],
                'the browser could not read the time');

            $this->jsClick($browser, '@schedule-ok');
            $browser->waitForText('Time: enter the whole time, or choose All day.')
                ->assertVisible('@schedule-modal');

            // Nothing was staged: Cancel leaves the line with no schedule and nothing to save.
            $this->jsClick($browser, '@schedule-cancel');
            $this->waitForModalClosed($browser, '@schedule-modal');
            $browser->assertMissing('@playlist-schedule-badge-0')->assertDisabled('@playlist-save');
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
