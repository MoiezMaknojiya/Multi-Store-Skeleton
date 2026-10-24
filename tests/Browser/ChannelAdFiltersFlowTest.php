<?php

namespace Tests\Browser;

use App\Models\Channel;
use App\Models\ChannelAd;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * A channel's ads, filtered (owner, 2026-10-23: "channel k ander 'Starts later' aur 'Running' ka filter … ya month ka
 * filter dalo … bhohot mushkil ho rae ha"). Status, Month and a search choose the rows shown, each count read under the
 * others; every row keeps its place number; an arrow moves an ad past the next one shown while the hidden ads keep their
 * places; the address keeps the filters through a reload; and a list the filters empty says so, with the way back. The
 * lists are opened with real presses sent to Chrome, a double one among them.
 */
class ChannelAdFiltersFlowTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_the_filters_choose_the_ads_shown_and_never_their_order(): void
    {
        $admin = $this->seedSuperAdmin();
        $channel = Channel::factory()->create(['name' => 'Coast to Coast']);

        $thisMonth = [now()->startOfMonth()->toDateString(), now()->endOfMonth()->toDateString()];
        $nextMonth = [now()->addMonthNoOverflow()->startOfMonth()->toDateString(), now()->addMonthNoOverflow()->endOfMonth()->toDateString()];
        $lastMonth = [now()->subMonthNoOverflow()->startOfMonth()->toDateString(), now()->subMonthNoOverflow()->endOfMonth()->toDateString()];
        $ads = [];
        foreach ([
            ['Ghost Energy', $thisMonth], ['Reign and Bang', $nextMonth], ['Red Bull 12oz', $thisMonth], ['Red Bull Winter', $nextMonth],
            ['Store Hours', [null, null]], ['Old Deal', $lastMonth], ['Celsius', $nextMonth],
        ] as $position => [$title, [$from, $to]]) {
            $ads[$title] = ChannelAd::factory()->showing(['thumbnail_path' => null])->create([
                'channel_id' => $channel->id, 'title' => $title, 'position' => $position, 'starts_on' => $from, 'ends_on' => $to,
            ]);
        }
        $row = fn (string $title) => '@channel-ad-row-'.$ads[$title]->id;
        $nextKey = substr($nextMonth[0], 0, 7);

        $this->browse(function (Browser $browser) use ($admin, $channel, $ads, $row, $nextKey) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/channels/'.$channel->id);
            $this->waitForAlpine($browser);
            $browser->waitFor($row('Celsius'));
            foreach (array_keys($ads) as $title) {
                $browser->assertVisible($row($title));
            }

            /* ── 1. Each status says how many it holds; a double press opens the list and leaves it open ── */
            $this->assertSame(['All Statuses', 'Running (3)', 'Starts later (3)', 'Ended (1)', 'Draft (0)'], $this->optionWords($browser, 'channel-ads-filter-status'));
            $select = '[dusk="channel-ads-filter-status"]';
            $this->presses($browser, $this->centreOf($browser, $select), 2);
            $browser->pause(500);
            $this->assertTrue((bool) $browser->script('return document.querySelector('.json_encode($select).').matches(":open");')[0]);
            $this->key($browser, 'Escape');

            /* ── 2. Starts later: its three ads alone, each with its place in the channel, and the address keeps it ── */
            $browser->select('@channel-ads-filter-status', 'scheduled')->waitUntilMissing($row('Ghost Energy'))
                ->assertVisible($row('Reign and Bang'))->assertVisible($row('Red Bull Winter'))->assertVisible($row('Celsius'))
                ->assertMissing($row('Store Hours'))->assertMissing($row('Old Deal'))
                ->assertSeeIn('@channel-ad-place-'.$ads['Reign and Bang']->id, '2')
                ->assertSeeIn('@channel-ad-place-'.$ads['Celsius']->id, '7');
            $this->assertStringContainsString('status=scheduled', $browser->driver->getCurrentURL());

            $browser->refresh();
            $this->waitForAlpine($browser);
            $browser->waitFor($row('Celsius'))->assertSelected('@channel-ads-filter-status', 'scheduled')->assertMissing($row('Ghost Energy'));

            /* ── 3. An arrow moves Reign and Bang past Red Bull Winter, the next one shown; the hidden ads keep their places ── */
            $browser->assertDisabled('@channel-ad-up-'.$ads['Reign and Bang']->id)->assertDisabled('@channel-ad-down-'.$ads['Celsius']->id)
                ->click('@channel-ad-down-'.$ads['Reign and Bang']->id);
            $browser->waitUsing(10, 200, fn () => ChannelAd::find($ads['Reign and Bang']->id)->position === 3);
            $this->assertSame(['Ghost Energy', 'Red Bull 12oz', 'Red Bull Winter', 'Reign and Bang', 'Store Hours', 'Old Deal', 'Celsius'],
                ChannelAd::where('channel_id', $channel->id)->orderBy('position')->pluck('title')->all());
            $browser->waitForTextIn('@channel-ad-place-'.$ads['Reign and Bang']->id, '4')
                ->assertSeeIn('@channel-ad-place-'.$ads['Red Bull Winter']->id, '3');

            /* ── 4. Next month: its ads and the one with no dates; the counts follow the month ── */
            $browser->select('@channel-ads-filter-status', '')->select('@channel-ads-filter-month', $nextKey)
                ->waitUntilMissing($row('Ghost Energy'))
                ->assertVisible($row('Reign and Bang'))->assertVisible($row('Red Bull Winter'))->assertVisible($row('Celsius'))->assertVisible($row('Store Hours'))
                ->assertMissing($row('Old Deal'));
            $this->assertSame(['All Statuses', 'Running (1)', 'Starts later (3)', 'Ended (0)', 'Draft (0)'], $this->optionWords($browser, 'channel-ads-filter-status'));
            $this->assertStringContainsString('month='.$nextKey, $browser->driver->getCurrentURL());

            /* ── 5. A search under the month; then one that matches nothing, and the way back ── */
            $browser->type('@crud-search', 'red')->waitUntilMissing($row('Celsius'))
                ->assertVisible($row('Red Bull Winter'))->assertMissing($row('Red Bull 12oz'));
            $browser->clear('@crud-search')->type('@crud-search', 'zzz')->waitFor('@channel-ads-no-match')
                ->assertSeeIn('@channel-ads-no-match', 'Nothing matches “zzz” under these filters.')
                ->assertVisible('@channel-ads-clear-search')->assertVisible('@channel-ads-clear-filters');

            $browser->click('@channel-ads-clear-filters')->pause(300)
                ->assertSelected('@channel-ads-filter-month', '')->assertSeeIn('@channel-ads-no-match', 'Nothing matches “zzz”.')
                ->assertMissing('@channel-ads-clear-filters');
            $browser->click('@channel-ads-clear-search')->waitFor($row('Old Deal'));
            foreach (array_keys($ads) as $title) {
                $browser->assertVisible($row($title));
            }
            $browser->pause(500);
            $address = $browser->driver->getCurrentURL();
            foreach (['status=', 'month=', 'search='] as $param) {
                $this->assertStringNotContainsString($param, $address);
            }
        });
    }

    /** The words of a select's options, as a person reads them. */
    private function optionWords(Browser $browser, string $dusk): array
    {
        return $browser->script('return [...document.querySelector(\'[dusk="'.$dusk.'"]\').options].map((option) => option.textContent.trim());')[0];
    }

    /** Presses sent to Chrome itself, with the click count a person's double or triple press carries. */
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

    private function key(Browser $browser, string $key): void
    {
        $tools = new ChromeDevToolsDriver($browser->driver);
        foreach (['keyDown', 'keyUp'] as $type) {
            $tools->execute('Input.dispatchKeyEvent', ['type' => $type, 'key' => $key, 'code' => $key, 'windowsVirtualKeyCode' => 27]);
        }
    }

    /** The middle of an element, brought into sight first. */
    private function centreOf(Browser $browser, string $css): array
    {
        return $browser->script('const el = document.querySelector('.json_encode($css).'); el.scrollIntoView({ block: "center" }); '
            .'const r = el.getBoundingClientRect(); return [Math.round(r.left + r.width / 2), Math.round(r.top + r.height / 2)];')[0];
    }
}
