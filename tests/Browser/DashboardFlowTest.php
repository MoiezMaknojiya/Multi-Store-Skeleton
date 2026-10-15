<?php

namespace Tests\Browser;

use App\Models\ActivityLog;
use App\Models\Organization;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Facebook\WebDriver\WebDriverKeys;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The dashboard as a person uses it (owner, 2026-09-30: "user friendly banao puri site ko"): a new organization's first
 * steps open the very thing they name on the page they lead to — a dialog once, the address forgetting it — the
 * platform's list of what needs a look leads where it is put right, and a keyboard reaches the page's own
 * content first, past the sidebar, while a dialog keeps it inside until it closes.
 */
class DashboardFlowTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_a_new_organizations_first_steps_open_what_each_one_names(): void
    {
        $this->seedSuperAdmin();
        $organization = Organization::factory()->create(['name' => 'Corner Organization']);
        $owner = $this->organizationMember($organization);

        $this->browse(function (Browser $browser) use ($owner) {
            $this->freshSession($browser);
            $browser->loginAs($owner)->visit('/dashboard')
                ->waitFor('@dashboard-organization')
                ->assertSeeIn('@dashboard-organization-name', 'Corner Organization')
                ->assertSeeIn('@dashboard-steps', 'Pair your first screen')
                ->assertSeeIn('@dashboard-steps-progress', '0 of 2 done')
                ->assertSeeIn('@dashboard-card-screens', 'None paired yet')
                // No buttons of the dashboard's own (owner, 2026-09-30): each thing starts on its own page.
                ->assertMissing('@dashboard-actions')
                ->assertTitle('Dashboard · '.config('app.name'));

            // Pair your first screen: the Screens page with its code dialog open, and the address without the flag.
            $browser->click('@dashboard-step-pair')
                ->waitForLocation('/screens')
                ->waitFor('[dusk="screen-pair-form"]')
                ->assertVisible('[dusk="screen-pair-form"]');
            $this->assertSame('', parse_url($browser->driver->getCurrentURL(), PHP_URL_QUERY) ?? '');

            // Refreshing does not ask again: the flag has gone from the address.
            $browser->refresh();
            $this->waitForAlpine($browser);
            $browser->pause(400)->assertMissing('[dusk="screen-pair-form"]');

            // Upload a picture or a video: the Media page, whose drop box is on the page itself.
            $browser->visit('/dashboard')->waitFor('@dashboard-step-upload')
                ->click('@dashboard-step-upload')
                ->waitForLocation('/media')
                ->waitForText('Drop files here');

            // A card is the way to its page.
            $browser->visit('/dashboard')->waitFor('@dashboard-card-media')
                ->click('@dashboard-card-media')
                ->waitForLocation('/media');
        });
    }

    /**
     * Light to dark and back changes at once (owner, 2026-10-15: the cards looked odd while it changed): the switch starts no CSS
     * transition anywhere on the page, both ways, while a card keeps its own for a hover.
     */
    public function test_switching_light_and_dark_starts_no_transition_and_a_card_still_eases_on_hover(): void
    {
        $this->seedSuperAdmin();
        $organization = Organization::factory()->create(['name' => 'Corner Organization']);
        $owner = $this->organizationMember($organization);

        $this->browse(function (Browser $browser) use ($owner) {
            $this->freshSession($browser);
            $browser->loginAs($owner)->visit('/dashboard')->waitFor('@dashboard-card-media');
            $browser->script("localStorage.setItem('darkMode', 'false'); document.documentElement.classList.remove('dark');");
            $browser->refresh()->waitFor('@dashboard-card-media');
            $this->waitForAlpine($browser);

            try {
                foreach ([true, false, true, false] as $dark) {
                    $browser->click($dark ? '[aria-label="Switch to dark mode"]' : '[aria-label="Switch to light mode"]');
                    $seen = $browser->script('return [document.documentElement.classList.contains("dark"),'
                        .' document.getAnimations().filter((a) => a.constructor.name === "CSSTransition").map((a) => a.effect.target.tagName + " " + a.transitionProperty)];')[0];

                    $this->assertSame($dark, $seen[0], 'the theme did not change');
                    $this->assertSame([], $seen[1], 'switching to '.($dark ? 'dark' : 'light').' eased colours: '.implode(', ', array_slice($seen[1], 0, 5)));
                    $browser->pause(300);
                }

                // Nothing held off afterwards: a card still eases its colours on a hover.
                $this->assertSame('0.15s', $browser->script('return getComputedStyle(document.querySelector(\'[dusk="dashboard-card-media"]\')).transitionDuration;')[0]);
                $this->assertFalse($browser->script('return document.documentElement.classList.contains("theme-switching");')[0]);
            } finally {
                $browser->script("localStorage.setItem('darkMode', 'false');");
            }
        });
    }

    /** Recent activity says the exact moment beside "1 hour ago", in the reader's own timezone (owner, 2026-10-15). */
    public function test_recent_activity_says_the_exact_moment_in_the_readers_own_timezone(): void
    {
        $admin = $this->seedSuperAdmin();
        ActivityLog::create(['action' => 'test.long_ago', 'description' => 'Something last year', 'actor_name' => 'Ali Raza'])
            ->forceFill(['created_at' => '2025-03-04 15:07:00'])->save();
        $recent = ActivityLog::create(['action' => 'test.recent', 'description' => 'Something an hour ago', 'actor_name' => 'Ali Raza']);
        $recent->forceFill(['created_at' => now()->subHour()])->save();

        // As the browser in Chicago writes it: no year for this one, which is this year there.
        $local = $recent->fresh()->created_at->setTimezone('America/Chicago');
        $expected = $local->format('M j').($local->year === now('America/Chicago')->year ? '' : $local->format(', Y')).', '.$local->format('g:i A');

        $this->browse(function (Browser $browser) use ($admin, $expected) {
            $this->freshSession($browser);
            $tools = new ChromeDevToolsDriver($browser->driver);
            $tools->execute('Emulation.setTimezoneOverride', ['timezoneId' => 'America/Chicago']);
            $tools->execute('Emulation.setLocaleOverride', ['locale' => 'en-US']);

            try {
                $browser->loginAs($admin)->visit('/dashboard')->waitFor('@dashboard-activity');
                // Chrome puts a narrow no-break space before AM/PM: read as a plain space.
                $said = $browser->script('return [...document.querySelectorAll(\'[dusk="dashboard-activity-entry"]\')].map((li) => li.innerText.replace(/\s+/g, " ").trim());')[0];

                $this->assertSame([
                    "Something an hour ago Ali Raza · 1 hour ago ({$expected})",
                    'Something last year Ali Raza · 1 year ago (Mar 4, 2025, 9:07 AM)',
                ], $said);
                // Still a time a machine reads, and no raw stamp left as a tooltip.
                $this->assertNull($browser->attribute('@dashboard-activity-when', 'title'));
                $this->assertNotEmpty($browser->attribute('@dashboard-activity-when', 'datetime'));

                $browser->resize(375, 812)->refresh()->waitFor('@dashboard-activity');
                $this->assertLessThanOrEqual(0, $browser->script('return document.documentElement.scrollWidth - document.documentElement.clientWidth;')[0], 'The page scrolls sideways on a phone');
                $browser->screenshot('dashboard-activity-exact-time-phone')->resize(1440, 900);
            } finally {
                $tools->execute('Emulation.setTimezoneOverride', ['timezoneId' => '']);
                $tools->execute('Emulation.setLocaleOverride', []);
            }
        });
    }

    public function test_the_platform_dashboard_says_which_organization_has_no_owner_and_leads_to_it(): void
    {
        $admin = $this->seedSuperAdmin();
        Organization::factory()->create(['name' => 'Orphan Organization']);
        $owned = Organization::factory()->create(['name' => 'Owned Organization']);
        $this->organizationMember($owned);

        $this->browse(function (Browser $browser) use ($admin) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/dashboard')
                ->waitFor('@dashboard-platform')
                ->assertSeeIn('@dashboard-card-organizations', '2')
                ->assertSeeIn('@dashboard-attention', 'Orphan Organization has no Owner')
                ->assertDontSeeIn('@dashboard-attention', 'Owned Organization');

            $browser->click('[dusk^="dashboard-attention-organization-ownerless-"]')
                ->waitForLocation('/organizations')
                ->waitForText('Orphan Organization');
        });
    }

    public function test_the_keyboard_skips_to_the_page_and_stays_inside_an_open_dialog(): void
    {
        $this->seedSuperAdmin();
        $organization = Organization::factory()->create(['name' => 'Corner Organization']);
        $owner = $this->organizationMember($organization);

        $this->browse(function (Browser $browser) use ($owner, $organization) {
            $this->freshSession($browser);
            $browser->loginAs($owner);
            $this->switchToOrganization($browser, $organization);
            $browser->visit('/screens');
            $this->waitForAlpine($browser);

            // The first Tab lands on the skip link, which shows itself…
            $browser->driver->getKeyboard()->sendKeys(WebDriverKeys::TAB);
            $skip = $browser->script(<<<'JS'
                const el = document.activeElement;
                const r = el.getBoundingClientRect();
                return { dusk: el.getAttribute('dusk'), width: r.width, height: r.height, top: r.top };
            JS)[0];
            $this->assertSame('skip-to-content', $skip['dusk']);
            $this->assertGreaterThan(40, $skip['width'], 'the skip link stays out of sight when focused');
            $this->assertGreaterThanOrEqual(0, $skip['top']);

            // …and Enter moves to the page's own content, past the sidebar.
            $browser->driver->getKeyboard()->sendKeys(WebDriverKeys::ENTER);
            $browser->waitUntil("document.activeElement && document.activeElement.id === 'main-content'");

            // One heading names the page, and the tab says it too.
            $this->assertSame(['Screens'], $browser->script("return [...document.querySelectorAll('h1')].map(h => h.textContent.trim());")[0]);
            $browser->assertTitle('Screens · '.config('app.name'));

            // A dialog is a named dialog, and Tab goes round inside it while it is open…
            $browser->click('@add-screen')->waitFor('[dusk="screen-pair-form"]');
            $dialog = $browser->script(<<<'JS'
                const panel = document.querySelector('[dusk="screen-pair-form"]').closest('[role="dialog"]');
                const title = panel && document.getElementById(panel.getAttribute('aria-labelledby'));
                return { modal: panel?.getAttribute('aria-modal'), title: title?.textContent.trim() };
            JS)[0];
            $this->assertSame(['modal' => 'true', 'title' => 'Add Screen'], $dialog);

            foreach (range(1, 12) as $press) {
                $browser->driver->getKeyboard()->sendKeys(WebDriverKeys::TAB);
                $this->assertTrue(
                    $browser->script("return !!document.activeElement.closest('[role=\"dialog\"]');")[0],
                    "Tab number {$press} left the open dialog"
                );
            }

            // …and Escape closes it and gives the keyboard back to the button that opened it.
            $browser->driver->getKeyboard()->sendKeys(WebDriverKeys::ESCAPE);
            $browser->waitUntilMissing('[dusk="screen-pair-form"]')
                ->waitUntil("document.activeElement && document.activeElement.getAttribute('dusk') === 'add-screen'");
        });
    }
}
