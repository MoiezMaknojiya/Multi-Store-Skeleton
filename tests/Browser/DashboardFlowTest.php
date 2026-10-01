<?php

namespace Tests\Browser;

use App\Models\Store;
use Facebook\WebDriver\WebDriverKeys;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The dashboard as a person uses it (owner, 2026-09-30: "user friendly banao puri site ko"): a new shop's first
 * steps open the very thing they name on the page they lead to — a dialog once, the address forgetting it — the
 * platform's list of what needs a look leads where it is put right, and a keyboard reaches the page's own
 * content first, past the sidebar, while a dialog keeps it inside until it closes.
 */
class DashboardFlowTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_a_new_shops_first_steps_open_what_each_one_names(): void
    {
        $this->seedSuperAdmin();
        $shop = Store::factory()->create(['name' => 'Corner Shop']);
        $owner = $this->storeMember($shop);

        $this->browse(function (Browser $browser) use ($owner) {
            $this->freshSession($browser);
            $browser->loginAs($owner)->visit('/dashboard')
                ->waitFor('@dashboard-store')
                ->assertSeeIn('@dashboard-store-name', 'Corner Shop')
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

    public function test_the_platform_dashboard_says_which_store_has_no_owner_and_leads_to_it(): void
    {
        $admin = $this->seedSuperAdmin();
        Store::factory()->create(['name' => 'Orphan Store']);
        $owned = Store::factory()->create(['name' => 'Owned Store']);
        $this->storeMember($owned);

        $this->browse(function (Browser $browser) use ($admin) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/dashboard')
                ->waitFor('@dashboard-platform')
                ->assertSeeIn('@dashboard-card-stores', '2')
                ->assertSeeIn('@dashboard-attention', 'Orphan Store has no Owner')
                ->assertDontSeeIn('@dashboard-attention', 'Owned Store');

            $browser->click('[dusk^="dashboard-attention-store-ownerless-"]')
                ->waitForLocation('/stores')
                ->waitForText('Orphan Store');
        });
    }

    public function test_the_keyboard_skips_to_the_page_and_stays_inside_an_open_dialog(): void
    {
        $this->seedSuperAdmin();
        $shop = Store::factory()->create(['name' => 'Corner Shop']);
        $owner = $this->storeMember($shop);

        $this->browse(function (Browser $browser) use ($owner, $shop) {
            $this->freshSession($browser);
            $browser->loginAs($owner);
            $this->switchToStore($browser, $shop);
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
