<?php

namespace Tests\Browser;

use App\Models\Role;
use App\Models\Store;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * A store the platform pauses, as its people live it (owner, 2026-09-30): a page left open when it is paused sends
 * them to the page that says why at its next request; another store of theirs opens from the menu at the top; and
 * when the platform turns it back on, its pages open again.
 */
class PausedStoreFlowTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_a_page_open_when_the_store_is_paused_leads_to_why_and_the_store_comes_back_when_turned_on(): void
    {
        $this->seedSuperAdmin();
        $alpha = Store::factory()->create(['name' => 'Alpha Mart']);
        $beta = Store::factory()->create(['name' => 'Beta Mart']);
        $owner = $this->storeMember($alpha);
        $owner->stores()->attach($beta->id, ['role_id' => Role::owner()->id]);

        $this->browse(function (Browser $browser) use ($owner, $alpha, $beta) {
            $this->freshSession($browser);
            $browser->loginAs($owner);
            $this->switchToStore($browser, $alpha);
            $browser->visit('/media');
            $this->waitForAlpine($browser);
            $browser->waitUntilMissingText('Loading...', 10);

            // The platform pauses it while the page is open…
            $alpha->update(['is_active' => false]);

            // …and the next thing the page asks for says so and leads to the dashboard.
            $this->jsType($browser, '@crud-search', 'poster');
            $browser->waitForText('Alpha Mart is paused. Contact support to turn it back on.', 10)
                ->waitForLocation('/dashboard', 10)
                ->waitFor('@dashboard-paused')
                ->assertSeeIn('@dashboard-paused', 'Alpha Mart is paused')
                ->assertSeeIn('@dashboard-paused', 'Choose Another Organization');

            // The menu offers none of its pages.
            $browser->assertMissing('a[href$="/screens"]')->assertMissing('a[href$="/media"]');

            // Another of their stores opens from the switcher at the top.
            $browser->click('@store-switcher')->waitFor('@store-switch-'.$beta->id);
            $browser->waitForReload(fn (Browser $b) => $b->click('@store-switch-'.$beta->id));
            $browser->visit('/screens')->assertPathIs('/screens');

            // Turned back on: the paused store's pages open again.
            $alpha->update(['is_active' => true]);
            $this->switchToStore($browser, $alpha);
            $browser->visit('/media')->assertPathIs('/media');
            $this->waitForAlpine($browser);
            $browser->assertMissing('@dashboard-paused');
        });
    }

    public function test_the_platform_pauses_a_store_from_its_page_and_sees_it_said(): void
    {
        $admin = $this->seedSuperAdmin();
        $alpha = Store::factory()->create(['name' => 'Alpha Mart']);

        $this->browse(function (Browser $browser) use ($admin, $alpha) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/stores');
            $this->waitForAlpine($browser);
            $browser->waitForText('Alpha Mart');

            $this->clickAndAwait($browser, '@edit-store-'.$alpha->id, fn (Browser $b) => $b->waitFor('@store-active', 3));
            $browser->assertSee('Off pauses the organization: its people cannot open it, and its screens keep playing.');
            $browser->uncheck('@store-active');
            $this->jsClick($browser, '@store-save');

            $browser->waitForText('Alpha Mart is paused.', 10);
            $browser->waitUsing(10, 200, fn () => $alpha->fresh()->is_active === false);
            $browser->waitForTextIn('table', 'Paused');
        });
    }
}
