<?php

namespace Tests\Browser;

use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Media;
use App\Models\Organization;
use App\Models\Screen;
use App\Models\User;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The card beside a screen's playlist: Content Library and Channels as two tabs of one card (owner, 2026-10-07 —
 * "jo screen k ander 2 card arae ha "Content library" aur "channel" us ek card kar k tab bana du", then (a): no
 * Channels tab while the screen has no channel to carry).
 *
 * Pressed as a person presses — presses sent to Chrome itself (Input.dispatchMouseEvent) with the click count a
 * double or triple click carries, fast runs of them, a slow line — and watched while it changes: exactly one panel
 * drawn at every moment, nothing thrown, the page never left.
 */
class PlaylistPickerTabsTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_the_card_opens_on_the_library_and_each_tab_shows_its_own_list(): void
    {
        [$owner, $alpha, $screen, $gama, $ours] = $this->screenWithChannels();

        $this->browse(function (Browser $browser) use ($owner, $alpha, $screen, $gama, $ours) {
            $this->openScreen($browser, $owner, $alpha, $screen);

            // Opened on the library, the tab row written before Alpine starts and each tab counting its own.
            $browser->waitFor('@picker-tab-channels')
                ->assertAttribute('@picker-tab-library', 'aria-selected', 'true')
                ->assertAttribute('@picker-tab-channels', 'aria-selected', 'false')
                ->assertSeeIn('@picker-count-library', '2')
                ->assertSeeIn('@picker-count-channels', '2')
                ->assertVisible('@media-picker')
                ->assertMissing('@channel-picker')
                ->assertSeeIn('@media-picker', 'Burger deal')
                ->assertDontSeeIn('@media-picker', 'Platform promo')
                ->screenshot('picker-tabs-library');

            $this->presses($browser, $this->centreOf($browser, '[dusk="picker-tab-channels"]'), 1);
            $browser->waitFor('@channel-picker')
                ->assertMissing('@media-picker')
                ->assertAttribute('@picker-tab-channels', 'aria-selected', 'true')
                ->assertAttribute('@picker-tab-library', 'aria-selected', 'false')
                ->assertSeeIn('@channel-picker', 'GAMA')
                ->assertSeeIn('@channel-picker', 'Our deals')
                ->assertVisible('@channel-picker-own-'.$ours->id)
                ->assertMissing('@channel-picker-own-'.$gama->id)
                ->screenshot('picker-tabs-channels');

            $this->presses($browser, $this->centreOf($browser, '[dusk="picker-tab-library"]'), 1);
            $browser->waitFor('@media-picker')->assertMissing('@channel-picker');

            // By keyboard: Tab reaches each tab and Enter opens it.
            $browser->script("document.querySelector('[dusk=\"picker-tab-channels\"]').focus()");
            $browser->keys('@picker-tab-channels', '{enter}')
                ->waitFor('@channel-picker')
                ->assertMissing('@media-picker');

            $this->assertCalm($browser, $screen);
        });
    }

    public function test_a_screen_with_no_channel_has_no_channels_tab_until_the_platform_makes_one(): void
    {
        [$owner, $alpha, $screen] = $this->screenWithFiles();

        $this->browse(function (Browser $browser) use ($owner, $alpha, $screen) {
            $this->openScreen($browser, $owner, $alpha, $screen);

            $browser->waitFor('@picker-count-library')
                ->assertSeeIn('@picker-count-library', '2')
                ->assertVisible('@media-picker');
            $this->waitForTheChannelsAnswer($browser);
            $browser->assertMissing('@picker-tab-channels')->assertMissing('@channel-picker');

            // Nothing can leave the card on a tab that is not there: it shows the library whatever it is told.
            $browser->script('Alpine.$data(document.querySelector(\'[x-data^="screenPlaylist"]\')).pickerTab = "channels";');
            $browser->pause(200)->assertVisible('@media-picker')->assertMissing('@channel-picker')
                ->assertAttribute('@picker-tab-library', 'aria-selected', 'true');

            // The super admin makes a channel for every organization: the next time the page opens, it has its tab.
            $platformChannel = Channel::factory()->create(['name' => 'GAMA']);
            $browser->refresh();
            $this->waitForAlpine($browser);
            $this->watch($browser);
            $browser->waitFor('@picker-tab-channels')
                ->assertSeeIn('@picker-count-channels', '1')
                ->assertVisible('@media-picker');
            $this->presses($browser, $this->centreOf($browser, '[dusk="picker-tab-channels"]'), 1);
            $browser->waitFor('@playlist-add-channel-'.$platformChannel->id);

            $this->assertCalm($browser, $screen);
        });
    }

    public function test_the_tabs_pressed_hard_with_a_real_mouse(): void
    {
        [$owner, $alpha, $screen] = $this->screenWithChannels();

        $this->browse(function (Browser $browser) use ($owner, $alpha, $screen) {
            $this->openScreen($browser, $owner, $alpha, $screen);
            $browser->waitFor('@picker-tab-channels');
            $library = $this->centreOf($browser, '[dusk="picker-tab-library"]');
            $channels = $this->centreOf($browser, '[dusk="picker-tab-channels"]');

            // A double click opens the tab and leaves it open; so does a triple.
            $this->presses($browser, $channels, 2);
            $browser->pause(300)->assertVisible('@channel-picker')->assertMissing('@media-picker');
            $this->presses($browser, $library, 3);
            $browser->pause(300)->assertVisible('@media-picker')->assertMissing('@channel-picker');

            // A storm of presses from one tab to the other: the last one pressed is the one open.
            for ($round = 0; $round < 12; $round++) {
                $this->presses($browser, $round % 2 === 0 ? $library : $channels, 1);
                usleep(40 * 1000);
            }
            $browser->pause(300)->assertVisible('@channel-picker')->assertMissing('@media-picker')
                ->assertAttribute('@picker-tab-channels', 'aria-selected', 'true');

            // A search is kept while the other tab is looked at, and the library's count stays put while it narrows.
            $this->presses($browser, $library, 1);
            $browser->waitFor('@media-picker');
            $this->jsType($browser, '@media-picker-search', 'burger');
            $browser->waitUntilMissingText('Coffee morning')->assertSeeIn('@media-picker', 'Burger deal')
                ->assertSeeIn('@picker-count-library', '2');
            $this->presses($browser, $channels, 2);
            $browser->pause(200);
            $this->presses($browser, $library, 2);
            $browser->pause(300)->assertInputValue('@media-picker-search', 'burger')
                ->assertSeeIn('@media-picker', 'Burger deal')->assertDontSeeIn('@media-picker', 'Coffee morning');

            $this->assertSame([1], $this->watched($browser)['panels'], 'At some moment the card drew no panel, or both');
            $this->assertCalm($browser, $screen);
        });
    }

    public function test_a_double_click_on_add_puts_one_line_on_the_playlist(): void
    {
        [$owner, $alpha, $screen, $gama] = $this->screenWithChannels();
        $burger = Media::where('title', 'Burger deal')->firstOrFail();

        $this->browse(function (Browser $browser) use ($owner, $alpha, $screen, $gama, $burger) {
            $this->openScreen($browser, $owner, $alpha, $screen);
            $browser->waitFor('@playlist-add-'.$burger->id);

            $this->presses($browser, $this->centreOf($browser, '[dusk="playlist-add-'.$burger->id.'"]'), 2);
            $browser->pause(400);
            $this->assertSame(1, $this->lineCount($browser), 'A double click on a file\'s Add put it on the playlist twice');

            $this->presses($browser, $this->centreOf($browser, '[dusk="picker-tab-channels"]'), 1);
            $browser->waitFor('@playlist-add-channel-'.$gama->id);
            $this->presses($browser, $this->centreOf($browser, '[dusk="playlist-add-channel-'.$gama->id.'"]'), 3);
            $browser->pause(400);
            $this->assertSame(2, $this->lineCount($browser), 'A triple click on a channel\'s Add put it on the playlist more than once');

            // Adding stays on the tab it was done from.
            $browser->assertVisible('@channel-picker')->assertMissing('@media-picker');

            // Two presses a moment apart are two choices: somebody who wants it twice still gets it twice.
            $at = $this->centreOf($browser, '[dusk="playlist-add-channel-'.$gama->id.'"]');
            $this->presses($browser, $at, 1);
            $browser->pause(700);
            $this->presses($browser, $at, 1);
            $browser->pause(400);
            $this->assertSame(4, $this->lineCount($browser));

            $this->assertCalm($browser, $screen);
        });
    }

    public function test_on_a_slow_line_the_tabs_add_and_save_hold_together(): void
    {
        [$owner, $alpha, $screen, $gama] = $this->screenWithChannels();
        $burger = Media::where('title', 'Burger deal')->firstOrFail();

        $this->browse(function (Browser $browser) use ($owner, $alpha, $screen, $gama, $burger) {
            $this->freshSession($browser);
            $browser->loginAs($owner);
            $this->switchToOrganization($browser, $alpha);
            $browser->visit('/dashboard');
            $this->network($browser, 1200);

            $browser->visit('/screens/'.$screen->id);
            $this->waitForAlpine($browser);
            $this->watch($browser);

            // While the lists are on their way the card says so on its open tab, and the Channels tab waits for
            // its list: pressing where it will be does nothing.
            $browser->waitFor('@picker-tab-library')->assertSeeIn('@media-picker', 'Loading...');

            $browser->waitFor('@picker-tab-channels', 15);
            $this->presses($browser, $this->centreOf($browser, '[dusk="picker-tab-channels"]'), 2);
            $browser->waitFor('@playlist-add-channel-'.$gama->id);
            $this->presses($browser, $this->centreOf($browser, '[dusk="playlist-add-channel-'.$gama->id.'"]'), 1);
            $this->presses($browser, $this->centreOf($browser, '[dusk="picker-tab-library"]'), 1);
            $browser->waitFor('@playlist-add-'.$burger->id, 15);
            $this->presses($browser, $this->centreOf($browser, '[dusk="playlist-add-'.$burger->id.'"]'), 1);
            $this->assertSame(2, $this->lineCount($browser));

            // Saving on the slow line, the tabs pressed meanwhile: the save lands whole and the open tab stays.
            $this->jsClick($browser, '@playlist-save');
            $this->presses($browser, $this->centreOf($browser, '[dusk="picker-tab-channels"]'), 1);
            $this->presses($browser, $this->centreOf($browser, '[dusk="picker-tab-library"]'), 2);
            $browser->waitUsing(20, 250, fn () => $browser->script(
                'return Alpine.$data(document.querySelector(\'[x-data^="screenPlaylist"]\')).dirty === false;'
            )[0] === true);
            $browser->assertVisible('@media-picker')->assertMissing('@channel-picker');
            $this->assertSame(2, $screen->playlistItems()->count());

            $this->network($browser, 0);
            $browser->refresh();
            $this->waitForAlpine($browser);
            $browser->waitFor('@picker-tab-channels')
                ->assertAttribute('@picker-tab-library', 'aria-selected', 'true')
                ->assertVisible('@media-picker');
        });
    }

    public function test_on_a_phone_both_tabs_stand_on_one_line_and_the_page_never_scrolls_sideways(): void
    {
        [$owner, $alpha, $screen] = $this->screenWithChannels();

        $this->browse(function (Browser $browser) use ($owner, $alpha, $screen) {
            $browser->resize(375, 812);
            $this->openScreen($browser, $owner, $alpha, $screen);
            $browser->waitFor('@picker-tab-channels');

            $layout = $browser->script(<<<'JS'
                const library = document.querySelector('[dusk="picker-tab-library"]').getBoundingClientRect();
                const channels = document.querySelector('[dusk="picker-tab-channels"]').getBoundingClientRect();
                const card = document.querySelector('[dusk="playlist-picker"]').getBoundingClientRect();
                return {
                    sameLine: Math.abs(library.top - channels.top) < 1,
                    inside: channels.right <= card.right,
                    wider: document.documentElement.scrollWidth - document.documentElement.clientWidth,
                };
            JS)[0];

            $this->assertTrue($layout['sameLine'], 'The two tabs wrapped onto two lines on a phone');
            $this->assertTrue($layout['inside'], 'The Channels tab runs out of its card on a phone');
            $this->assertLessThanOrEqual(0, $layout['wider'], 'The page scrolls sideways on a phone');
            $browser->script("document.querySelector('[dusk=\"playlist-picker\"]').scrollIntoView({ block: 'start' })");
            $browser->screenshot('picker-tabs-phone');
            $browser->resize(1440, 900);
        });
    }

    /* ── The screen, set up ─────────────────────────────────────────────── */

    /** @return array{0: User, 1: Organization, 2: Screen} two files of its own to add, and the platform's, which its Content Library never offers */
    private function screenWithFiles(): array
    {
        $this->seedSuperAdmin();
        $alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->organizationMember($alpha, ['screen-view', 'screen-playlist']);
        $screen = Screen::factory()->create(['organization_id' => $alpha->id, 'name' => 'Counter TV']);

        Media::factory()->create(['organization_id' => $alpha->id, 'title' => 'Burger deal', 'thumbnail_path' => null]);
        Media::factory()->create(['organization_id' => $alpha->id, 'title' => 'Coffee morning', 'thumbnail_path' => null]);
        Media::factory()->platformOwned()->create(['title' => 'Platform promo', 'thumbnail_path' => null]);

        return [$owner, $alpha, $screen];
    }

    /** @return array{0: User, 1: Organization, 2: Screen, 3: Channel, 4: Channel} and two channels: the platform's and its own */
    private function screenWithChannels(): array
    {
        [$owner, $alpha, $screen] = $this->screenWithFiles();

        // The channel's file stays off the library (rule 6 of Channels), so the library still counts two.
        $gama = Channel::factory()->create(['name' => 'GAMA']);
        ChannelAd::factory()->lasting(10)->showing(['thumbnail_path' => null])->create(['channel_id' => $gama->id, 'title' => 'Monster', 'position' => 0]);
        $ours = Channel::factory()->create(['organization_id' => $alpha->id, 'name' => 'Our deals']);

        return [$owner, $alpha, $screen, $gama, $ours];
    }

    private function openScreen(Browser $browser, User $owner, Organization $organization, Screen $screen): void
    {
        $this->freshSession($browser);
        $browser->loginAs($owner);
        $this->switchToOrganization($browser, $organization);
        $browser->visit('/screens/'.$screen->id);
        $this->waitForAlpine($browser);
        $browser->waitFor('@playlist-picker');
        $this->watch($browser);
    }

    /** The channels' list has answered — after the library's, which the page asks for first. */
    private function waitForTheChannelsAnswer(Browser $browser): void
    {
        $browser->waitUsing(10, 100, fn () => $browser->script(
            "return performance.getEntriesByType('resource').some((r) => r.name.includes('/available-channels') && r.responseEnd > 0);"
        )[0] === true)->pause(200);
    }

    private function lineCount(Browser $browser): int
    {
        return (int) $browser->script('return Alpine.$data(document.querySelector(\'[x-data^="screenPlaylist"]\')).items.length;')[0];
    }

    /* ── Watching the page ──────────────────────────────────────────────── */

    /** Errors thrown or logged, and how many of the card's two panels are drawn, sampled every few milliseconds. */
    private function watch(Browser $browser): void
    {
        $browser->script(<<<'JS'
            if (window.__w) return;
            window.__w = { errors: [], panels: new Set() };
            window.addEventListener('error', (e) => window.__w.errors.push(String(e.message)));
            window.addEventListener('unhandledrejection', (e) => window.__w.errors.push(String(e.reason)));
            const said = console.error;
            console.error = function (...parts) { window.__w.errors.push(parts.map(String).join(' ')); return said.apply(this, parts); };
            const shown = (name) => document.querySelector(`[dusk="${name}"]`)?.getClientRects().length > 0 ? 1 : 0;
            setInterval(() => window.__w.panels.add(shown('media-picker') + shown('channel-picker')), 5);
        JS);
    }

    private function watched(Browser $browser): array
    {
        return $browser->script('return { errors: window.__w.errors, panels: [...window.__w.panels] }')[0];
    }

    /** Still on the screen's page, and nothing thrown or logged as an error. */
    private function assertCalm(Browser $browser, Screen $screen): void
    {
        $this->assertSame('/screens/'.$screen->id, parse_url($browser->driver->getCurrentURL(), PHP_URL_PATH));
        $this->assertSame([], $this->watched($browser)['errors'], 'The page threw or logged errors');
    }

    /* ── A person's mouse, and the line ─────────────────────────────────── */

    /** $times presses of a real mouse on one place, $gap ms apart, each counting on from the last as Chrome counts them. */
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

    /** The middle of an element, brought into sight first. */
    private function centreOf(Browser $browser, string $css): array
    {
        return $browser->script('const el = document.querySelector('.json_encode($css).'); el.scrollIntoView({ block: "center" }); '
            .'const r = el.getBoundingClientRect(); return [Math.round(r.left + r.width / 2), Math.round(r.top + r.height / 2)];')[0];
    }

    /** Latency in ms for every request. */
    private function network(Browser $browser, int $latency): void
    {
        $tools = new ChromeDevToolsDriver($browser->driver);
        $tools->execute('Network.enable');
        $tools->execute('Network.emulateNetworkConditions', ['offline' => false, 'latency' => $latency, 'downloadThroughput' => -1, 'uploadThroughput' => -1]);
    }
}
