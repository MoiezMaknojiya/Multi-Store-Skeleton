<?php

namespace Tests\Browser;

use App\Models\Channel;
use App\Models\Organization;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Add Channel above the organizations asks whom it is for (owner, 2026-10-08): All organizations — the platform's, unlocked with
 * Platform Channels — or one organization, whose own free channel it is. Pressed with a real mouse: the choice, its words, a
 * triple-pressed Save that makes one channel, and an edit that keeps whom it is for.
 */
class ChannelForOneOrganizationFlowTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_the_platform_makes_a_free_channel_for_one_organization(): void
    {
        $admin = $this->seedSuperAdmin();
        $alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
        Organization::factory()->create(['name' => 'Beta Foods']);

        $this->browse(function (Browser $browser) use ($admin, $alpha) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/channels');
            $this->waitForAlpine($browser);
            $browser->waitFor('@add-channel');

            $this->presses($browser, $this->centreOf($browser, '[dusk="add-channel"]'), 2);
            $browser->waitFor('@channel-organization')->pause(300)
                ->assertSelected('@channel-organization', '')
                ->assertSeeIn('@channel-organization-hint', "For every organization's screens, once its Platform Channels are unlocked ($10).")
                ->select('@channel-organization', (string) $alpha->id)
                ->waitForTextIn('@channel-organization-hint', "Free: that organization's own channel, for its screens alone.")
                ->screenshot('channel-for-one-organization');

            $this->jsType($browser, '@channel-name', 'Alpha Deals');
            $this->countRequests($browser, 'POST', '/channels');
            $this->presses($browser, $this->centreOf($browser, '[dusk="channel-save"]'), 3);
            $browser->waitUsing(10, 200, fn () => Channel::where('name', 'Alpha Deals')->exists());
            $browser->pause(800);

            $this->assertSame(1, $this->requestsCounted($browser), 'Save sent more than once');
            $channel = Channel::where('name', 'Alpha Deals')->sole();
            $this->assertSame($alpha->id, $channel->organization_id);

            $browser->waitFor('@channel-reach-'.$channel->id)->assertSeeIn('@channel-reach-'.$channel->id, 'Alpha Mart only');

            // Edited, it says whom it is for, and asks no more.
            $this->presses($browser, $this->centreOf($browser, '[dusk="edit-channel-'.$channel->id.'"]'), 1);
            $browser->waitFor('@channel-organization-fixed')
                ->assertSeeIn('@channel-organization-fixed', 'For Alpha Mart.')
                ->assertMissing('@channel-organization');
        });
    }

    private function presses(Browser $browser, array $at, int $times, int $gap = 120): void
    {
        $tools = new ChromeDevToolsDriver($browser->driver);
        $tools->execute('Input.dispatchMouseEvent', ['type' => 'mouseMoved', 'x' => $at[0], 'y' => $at[1]]);

        for ($press = 1; $press <= $times; $press++) {
            foreach (['mousePressed', 'mouseReleased'] as $type) {
                $tools->execute('Input.dispatchMouseEvent', [
                    'type' => $type, 'x' => $at[0], 'y' => $at[1], 'button' => 'left',
                    'buttons' => $type === 'mousePressed' ? 1 : 0, 'clickCount' => min($press, 3),
                ]);
            }
            if ($press < $times) {
                usleep($gap * 1000);
            }
        }
    }

    private function centreOf(Browser $browser, string $css): array
    {
        return $browser->script('const el = document.querySelector('.json_encode($css).'); el.scrollIntoView({ block: "center" }); '
            .'const r = el.getBoundingClientRect(); return [Math.round(r.left + r.width / 2), Math.round(r.top + r.height / 2)];')[0];
    }
}
