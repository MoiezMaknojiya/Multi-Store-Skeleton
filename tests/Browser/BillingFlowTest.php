<?php

namespace Tests\Browser;

use App\Models\ActivityLog;
use App\Models\BuilderAd;
use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Media;
use App\Models\Organization;
use App\Models\PlaylistItem;
use App\Models\Role;
use App\Models\Screen;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Billing (owner, 2026-10-07 and 2026-10-08; docs/BILLING-SPEC.md): Settings → Billing inside an organization, Billing beside Edit on the
 * platform's Organizations page with its two switches, and the locks an organization meets once the platform turns one off.
 *
 * Pressed as a person presses — presses sent to Chrome itself (Input.dispatchMouseEvent) with the click count a double or triple click
 * carries, a slow line, a phone — and watched: one request per Save, the dialog of the row asked for, nothing thrown.
 */
class BillingFlowTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_the_owner_reads_settings_billing_from_its_tab(): void
    {
        [$owner, $smart] = $this->smartStop();

        $this->browse(function (Browser $browser) use ($owner, $smart) {
            $this->freshSession($browser);
            $browser->loginAs($owner);
            $this->switchToOrganization($browser, $smart);
            $browser->visit('/profile');
            $this->waitForAlpine($browser);
            $this->watch($browser);

            $this->presses($browser, $this->centreOf($browser, '[dusk="settings-tab-billing"]'), 1);
            $browser->waitFor('@billing-page')
                ->assertAttribute('@settings-tab-billing', 'aria-current', 'page')
                ->assertSeeIn('@billing-monthly-total', '$15')
                ->assertSee('3 more screens × $5')
                ->assertSeeIn('@billing-feature-premium_templates', 'Unlocked')
                ->assertSeeIn('@billing-feature-platform_channels', 'Unlocked')
                ->assertSeeIn('@billing-screens', 'Tv1')
                ->assertSeeIn('@billing-screens', 'Total')
                ->assertSeeIn('@billing-contact', 'Contact us')
                ->screenshot('billing-tab');
            $this->assertSame('Settings · Laravel', $browser->driver->getTitle());
            $this->assertCalm($browser);

            $browser->resize(375, 812)->refresh();
            $browser->waitFor('@billing-page');
            $this->assertFitsThePhone($browser, '[dusk="billing-page"]');
            $browser->screenshot('billing-tab-phone')->resize(1440, 900);
        });
    }

    public function test_the_super_admin_turns_the_switches_from_the_row_pressed_hard(): void
    {
        [, $smart, $moiez] = $this->smartStop();
        $admin = $this->seedSuperAdmin();

        $this->browse(function (Browser $browser) use ($admin, $smart, $moiez) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/organizations');
            $this->waitForAlpine($browser);
            $browser->waitFor('@billing-organization-'.$smart->id);
            $this->watch($browser);

            // Beside Edit on the row.
            $this->assertTrue($browser->script("const b = document.querySelector('[dusk=\"billing-organization-{$smart->id}\"]'); return b.nextElementSibling?.getAttribute('dusk') === 'edit-organization-{$smart->id}';")[0],
                'Billing does not stand beside Edit');

            // A double click opens the dialog and leaves it open, on Smart Stop's numbers.
            $this->presses($browser, $this->centreOf($browser, '[dusk="billing-organization-'.$smart->id.'"]'), 2);
            $browser->waitForTextIn('@organization-billing', 'Billing · Smart Stop')
                ->waitForTextIn('@organization-billing-total', '$15 / month')
                ->assertSeeIn('@organization-billing-screens', '4 screens: the first is free, then $5 a month each')
                ->assertDisabled('@organization-billing-save')
                ->assertAttribute('@billing-switch-premium_templates', 'aria-checked', 'true');

            // A double press turns a switch once (owner, 2026-10-08: it went on and off at one press of a mouse that sends
            // two), and so does a triple; a press after the double click's time turns it back.
            $this->presses($browser, $this->centreOf($browser, '[dusk="billing-switch-premium_templates"]'), 2);
            $browser->pause(300)->assertAttribute('@billing-switch-premium_templates', 'aria-checked', 'false')->assertEnabled('@organization-billing-save');
            $browser->pause(400);
            $this->presses($browser, $this->centreOf($browser, '[dusk="billing-switch-premium_templates"]'), 3);
            $browser->pause(300)->assertAttribute('@billing-switch-premium_templates', 'aria-checked', 'true')->assertDisabled('@organization-billing-save');
            $browser->pause(400);

            // One press locks it; the line says what that means; Save, pressed three times, sends once.
            $this->presses($browser, $this->centreOf($browser, '[dusk="billing-switch-premium_templates"]'), 1);
            $browser->pause(200)->assertAttribute('@billing-switch-premium_templates', 'aria-checked', 'false')
                ->assertSee('Locked: Smart Stop sees the templates and cannot use them')
                ->assertEnabled('@organization-billing-save')
                ->screenshot('billing-dialog-switch');
            $this->countRequests($browser, 'PUT', '/organizations/'.$smart->id.'/billing');
            $this->presses($browser, $this->centreOf($browser, '[dusk="organization-billing-save"]'), 3);
            $browser->waitForText('Locked Premium Templates for Smart Stop.')->waitUntilMissing('@organization-billing');
            $this->assertSame(1, $this->requestsCounted($browser), 'Save sent more than once');

            $this->assertFalse($smart->fresh()->premium_templates_unlocked);
            $this->assertTrue($smart->fresh()->platform_channels_unlocked);
            $this->assertSame(1, ActivityLog::where('action', 'organization.billing_updated')->count());

            // Opened again it says so; on a slow line, a second row's Billing pressed while the first is loading shows that row.
            $this->network($browser, 1200);
            $this->presses($browser, $this->centreOf($browser, '[dusk="billing-organization-'.$smart->id.'"]'), 1);
            $browser->waitForTextIn('@organization-billing', 'Loading...');
            $this->key($browser, 'Escape');
            $browser->waitUntilMissing('@organization-billing');
            $this->presses($browser, $this->centreOf($browser, '[dusk="billing-organization-'.$moiez->id.'"]'), 1);
            $browser->waitForTextIn('@organization-billing-total', '$0 / month', 15)->pause(1800)
                ->assertSeeIn('@organization-billing', 'Billing · Moiez Store')
                ->assertSeeIn('@organization-billing-screens', '1 screen: the first is free');
            $this->network($browser, 0);
            $this->key($browser, 'Escape');
            $browser->waitUntilMissing('@organization-billing');

            $this->presses($browser, $this->centreOf($browser, '[dusk="billing-organization-'.$smart->id.'"]'), 1);
            $browser->waitFor('@billing-switch-premium_templates')->pause(400)
                ->assertAttribute('@billing-switch-premium_templates', 'aria-checked', 'false');

            $browser->resize(375, 812);
            $this->assertFitsThePhone($browser, '[dusk="organization-billing"]');
            $browser->resize(1440, 900);
            $this->assertCalm($browser);
        });
    }

    public function test_a_locked_organization_sees_its_locks_and_the_one_unlock_dialog(): void
    {
        [$owner, $smart] = $this->smartStop();
        $smart->forceFill(['premium_templates_unlocked' => false, 'platform_channels_unlocked' => false])->save();

        $template = BuilderAd::factory()->state(['organization_id' => null, 'name' => 'Burger menu'])->published()->create();
        $gama = Channel::factory()->create(['name' => 'GAMA']);
        ChannelAd::factory()->lasting(10)->showing(['thumbnail_path' => null])->create(['channel_id' => $gama->id, 'title' => 'Monster', 'position' => 0]);
        $own = Channel::factory()->create(['organization_id' => $smart->id, 'name' => 'Our deals']);
        $screen = Screen::where('organization_id', $smart->id)->orderBy('id')->first();

        $this->browse(function (Browser $browser) use ($owner, $smart, $template, $gama, $own, $screen) {
            $this->freshSession($browser);
            $browser->loginAs($owner);
            $this->switchToOrganization($browser, $smart);

            /* ── Premium Templates ─────────────────────────────────────── */
            $browser->visit('/builder');
            $this->waitForAlpine($browser);
            $this->watch($browser);
            $this->jsClick($browser, '@new-ad');
            $browser->waitFor('@new-ad-landscape');
            $this->jsClick($browser, '@new-ad-landscape');
            $this->jsClick($browser, '@new-ad-premium');
            $browser->waitFor('@template-'.$template->id)
                ->waitFor('@templates-locked')
                ->assertSeeIn('@templates-locked', 'Premium Templates are locked for Smart Stop.')
                ->assertVisible('@template-premium-'.$template->id)
                ->assertVisible('@template-preview-'.$template->id)
                ->assertMissing('@use-template-'.$template->id)
                ->screenshot('billing-templates-locked');

            // A double press opens the unlock dialog once, over the gallery; Escape closes it alone.
            $this->presses($browser, $this->centreOf($browser, '[dusk="unlock-template-'.$template->id.'"]'), 2);
            $browser->waitFor('@unlock-premium-templates')->pause(400)
                ->assertSeeIn('@unlock-premium-templates', 'Unlock Premium Templates')
                ->assertSeeIn('@unlock-premium-templates', '$10')
                ->assertSeeIn('@unlock-premium-templates', 'Contact us')
                ->screenshot('billing-unlock-dialog');
            $this->key($browser, 'Escape');
            $browser->waitUntilMissing('@unlock-premium-templates')->assertVisible('@premium-templates');
            $this->assertSame(0, BuilderAd::where('organization_id', $smart->id)->count());
            $this->assertCalm($browser);

            /* ── Platform Channels ─────────────────────────────────────── */
            $browser->visit('/screens/'.$screen->id);
            $this->waitForAlpine($browser);
            $this->watch($browser);
            $browser->waitFor('@picker-tab-channels');
            $this->presses($browser, $this->centreOf($browser, '[dusk="picker-tab-channels"]'), 1);
            $browser->waitFor('@channels-locked')
                ->assertSeeIn('@channels-locked', 'Platform channels are locked for Smart Stop.')
                ->assertVisible('@channel-picker-premium-'.$gama->id)
                ->assertMissing('@playlist-add-channel-'.$gama->id)
                ->assertVisible('@channel-preview-'.$gama->id)
                ->assertVisible('@playlist-add-channel-'.$own->id)
                ->assertMissing('@channel-picker-premium-'.$own->id)
                ->screenshot('billing-channels-locked');

            $this->presses($browser, $this->centreOf($browser, '[dusk="unlock-channel-'.$gama->id.'"]'), 2);
            $browser->waitFor('@unlock-platform-channels')->pause(400)
                ->assertSeeIn('@unlock-platform-channels', 'Unlock Platform Channels')
                ->assertSeeIn('@unlock-platform-channels', 'Your own channels stay free.');
            $this->presses($browser, $this->centreOf($browser, '[dusk="unlock-platform-channels-close"]'), 2);
            $browser->waitUntilMissing('@unlock-platform-channels')->pause(400);

            // The organization's own channel goes on as ever.
            $this->presses($browser, $this->centreOf($browser, '[dusk="playlist-add-channel-'.$own->id.'"]'), 1);
            $this->assertSame(1, (int) $browser->script('return Alpine.$data(document.querySelector(\'[x-data^="screenPlaylist"]\')).items.filter((i) => i.type === "channel").length;')[0]);
            $this->assertCalm($browser);
        });
    }

    public function test_a_locked_platform_channel_line_stays_marked_and_plays_again_once_unlocked(): void
    {
        [$owner, $smart] = $this->smartStop();
        $gama = Channel::factory()->create(['name' => 'GAMA']);
        ChannelAd::factory()->lasting(10)->showing(['thumbnail_path' => null])->create(['channel_id' => $gama->id, 'title' => 'Monster', 'position' => 0]);
        $screen = Screen::where('organization_id', $smart->id)->orderBy('id')->first();
        $toast = Media::where('organization_id', $smart->id)->sole();
        $coffee = Media::factory()->create(['organization_id' => $smart->id, 'title' => 'Iced coffee', 'thumbnail_path' => null]);
        $tea = Media::factory()->create(['organization_id' => $smart->id, 'title' => 'Masala tea', 'thumbnail_path' => null]);
        foreach ([$toast, $coffee, $tea] as $position => $file) {
            PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $file->id, 'duration_seconds' => 10, 'position' => $position]);
        }
        PlaylistItem::create(['screen_id' => $screen->id, 'channel_id' => $gama->id, 'position' => 3]);
        $smart->forceFill(['platform_channels_unlocked' => false])->save();

        $this->browse(function (Browser $browser) use ($owner, $smart, $screen, $gama, $coffee) {
            $this->freshSession($browser);
            $browser->loginAs($owner);
            $this->switchToOrganization($browser, $smart);

            $browser->visit('/screens/'.$screen->id);
            $this->waitForAlpine($browser);
            $this->watch($browser);

            // The line keeps its place, says it is locked, and offers Unlock and its removal alone.
            $browser->waitFor('@playlist-locked')
                ->assertSeeIn('@playlist-locked', 'GAMA is not playing: Platform Channels are locked for Smart Stop.')
                ->assertSeeIn('@playlist-locked', 'Its line stays here. Unlock Platform Channels and it plays again by itself, or take it out.')
                ->assertVisible('@playlist-premium-3')
                ->assertSeeIn('@playlist-channel-info-3', 'locked: not playing on the screen')
                ->assertMissing('@playlist-schedule-3')->assertMissing('@playlist-up-3')->assertMissing('@playlist-down-3')
                ->assertMissing('@playlist-length-3')
                ->assertVisible('@playlist-unlock-3')->assertVisible('@playlist-remove-3')
                ->assertVisible('@playlist-schedule-0')->assertMissing('@playlist-unlock-0')->assertMissing('@playlist-premium-0')
                ->assertSeeIn('@playlist-summary', '4 items · 3 playing')
                ->screenshot('billing-locked-line');

            // A double press on Unlock opens the dialog once; a double press on its Close shuts it and reaches nothing beneath.
            $this->presses($browser, $this->centreOf($browser, '[dusk="playlist-unlock-3"]'), 2);
            $browser->waitFor('@unlock-platform-channels')->pause(400)
                ->assertSeeIn('@unlock-platform-channels', 'Unlock Platform Channels');
            $this->presses($browser, $this->centreOf($browser, '[dusk="unlock-platform-channels-close"]'), 2);
            $browser->waitUntilMissing('@unlock-platform-channels')->pause(400);
            $this->assertSame(4, $this->lineCount($browser));
            $browser->assertButtonDisabled('@playlist-save');

            // One line out, so the page holds a change and its lines stand still from here on.
            $this->presses($browser, $this->centreOf($browser, '[dusk="playlist-remove-0"]'), 1);
            $browser->pause(800);
            $this->assertSame(3, $this->lineCount($browser));

            // A double press on ↑ moves the line once — not up, and then the line it swapped with back up over it.
            $this->presses($browser, $this->centreOf($browser, '[dusk="playlist-up-1"]'), 2);
            $browser->pause(800);
            $this->assertSame(['Masala tea', 'Iced coffee', 'GAMA'], $this->lineTitles($browser), 'A double press on ↑ undid itself');

            // A double press on the first line's × takes that line alone, though the next one moves up under the pointer.
            // (Past a double click's time from the dialog shutting, so its guard is not what keeps the second press off.)
            $this->presses($browser, $this->centreOf($browser, '[dusk="playlist-remove-0"]'), 2);
            $browser->pause(300);
            $this->assertSame(['Iced coffee', 'GAMA'], $this->lineTitles($browser), 'A double press on × took two lines');
            $browser->assertVisible('@playlist-unlock-1');

            // Saved as it stands, the locked line stays where it is.
            $this->presses($browser, $this->centreOf($browser, '[dusk="playlist-save"]'), 1);
            $browser->waitUsing(10, 200, fn () => PlaylistItem::where('screen_id', $screen->id)->count() === 2);
            $lines = PlaylistItem::where('screen_id', $screen->id)->orderBy('position')->get();
            $this->assertSame([null, $gama->id], $lines->pluck('channel_id')->all());
            $this->assertSame([$coffee->id, null], $lines->pluck('media_id')->all());

            // On a phone the line and the note fit.
            $browser->resize(375, 812)->pause(300);
            $this->assertFitsThePhone($browser, '[dusk="playlist-line-1"]');
            $this->assertFitsThePhone($browser, '[dusk="playlist-locked"]');
            $browser->screenshot('billing-locked-line-phone')->resize(1440, 900);

            // Unlocked, the same line plays again: nothing on the page is changed by hand.
            $smart->forceFill(['platform_channels_unlocked' => true])->save();
            $browser->visit('/screens/'.$screen->id);
            $this->waitForAlpine($browser);
            $browser->waitFor('@playlist-schedule-1')
                ->assertMissing('@playlist-locked')->assertMissing('@playlist-unlock-1')->assertMissing('@playlist-premium-1')
                ->assertVisible('@playlist-length-1')
                ->assertDontSeeIn('@playlist-summary', 'playing');
            $this->assertCalm($browser);
        });
    }

    private function lineTitles(Browser $browser): array
    {
        return $browser->script('return Alpine.$data(document.querySelector(\'[x-data^="screenPlaylist"]\')).items.map((i) => i.title);')[0];
    }

    private function lineCount(Browser $browser): int
    {
        return (int) $browser->script('return Alpine.$data(document.querySelector(\'[x-data^="screenPlaylist"]\')).items.length;')[0];
    }

    /* ── The organizations, set up ─────────────────────────────────────── */

    /** Smart Stop with its Owner and four screens, and Moiez Store with one. */
    private function smartStop(): array
    {
        $this->seedSuperAdmin();
        $smart = Organization::factory()->create(['name' => 'Smart Stop']);
        $moiez = Organization::factory()->create(['name' => 'Moiez Store']);
        $owner = $this->organizationMember($smart, Role::OWNER, 'owner@smartstop.example');

        foreach (['Tv1', 'Tv2', 'Tv3', 'Tv4'] as $i => $name) {
            Screen::factory()->create(['organization_id' => $smart->id, 'name' => $name, 'paired_at' => now()->subDays(2)->addMinutes($i)]);
        }
        Screen::factory()->create(['organization_id' => $moiez->id, 'name' => 'Counter TV', 'paired_at' => now()->subDay()]);
        Media::factory()->create(['organization_id' => $smart->id, 'title' => 'Texas Toast', 'thumbnail_path' => null]);

        return [$owner, $smart, $moiez];
    }

    /* ── Watching the page ──────────────────────────────────────────────── */

    private function watch(Browser $browser): void
    {
        $browser->script(<<<'JS'
            if (window.__w) return;
            window.__w = { errors: [] };
            window.addEventListener('error', (e) => window.__w.errors.push(String(e.message)));
            window.addEventListener('unhandledrejection', (e) => window.__w.errors.push(String(e.reason)));
            const said = console.error;
            console.error = function (...parts) { window.__w.errors.push(parts.map(String).join(' ')); return said.apply(this, parts); };
        JS);
    }

    private function assertCalm(Browser $browser): void
    {
        $this->assertSame([], $browser->script('return window.__w ? window.__w.errors : [];')[0], 'The page threw or logged errors');
    }

    /** The element is no wider than the phone, and neither is the page. */
    private function assertFitsThePhone(Browser $browser, string $css): void
    {
        $fit = $browser->script('const d = document.querySelector('.json_encode($css).'); const r = d.getBoundingClientRect();'
            .' return { inside: r.left >= -0.5 && r.right <= window.innerWidth + 0.5, own: d.scrollWidth - d.clientWidth,'
            .' page: document.documentElement.scrollWidth - document.documentElement.clientWidth };')[0];

        $this->assertTrue($fit['inside'], "{$css} runs out of the phone");
        $this->assertLessThanOrEqual(0, $fit['own'], "{$css} scrolls sideways on a phone");
        $this->assertLessThanOrEqual(0, $fit['page'], 'The page scrolls sideways on a phone');
    }

    /* ── A person's mouse and keyboard, and the line ────────────────────── */

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

    private function centreOf(Browser $browser, string $css): array
    {
        return $browser->script('const el = document.querySelector('.json_encode($css).'); el.scrollIntoView({ block: "center" }); '
            .'const r = el.getBoundingClientRect(); return [Math.round(r.left + r.width / 2), Math.round(r.top + r.height / 2)];')[0];
    }

    private function network(Browser $browser, int $latency): void
    {
        $tools = new ChromeDevToolsDriver($browser->driver);
        $tools->execute('Network.enable');
        $tools->execute('Network.emulateNetworkConditions', ['offline' => false, 'latency' => $latency, 'downloadThroughput' => -1, 'uploadThroughput' => -1]);
    }
}
