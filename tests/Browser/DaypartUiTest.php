<?php

namespace Tests\Browser;

use App\Models\Daypart;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Building a daypart through the real page.
 *
 * The backend tests prove the rules; this proves the shop owner can actually reach
 * them. The specific thing it guards against is a page that renders fine in a Blade
 * assertion and throws in a browser — an Alpine method the view calls but the
 * component never defined, a repeating row whose model never binds, a summary that
 * silently reads undefined. None of that shows up in a status code.
 */
class DaypartUiTest extends DuskTestCase
{
    use DatabaseMigrations;

    /** A shop owner who runs their own opening hours. */
    private function owner(Store $store): User
    {
        $this->seedSuperAdmin();

        return $this->storeMember($store, ['daypart-view', 'daypart-store', 'daypart-update', 'daypart-destroy']);
    }

    /**
     * The whole loop: build a daypart with an exception, read back what it says in
     * words, retire it, and delete it.
     */
    public function test_an_owner_builds_a_daypart_with_an_exception_then_retires_and_deletes_it(): void
    {
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->owner($store);

        $this->browse(function (Browser $browser) use ($owner, $store) {
            $this->freshSession($browser);
            $browser->loginAs($owner);
            $this->switchToStore($browser, $store);

            // -- Reached the way a shop owner reaches it -----------------------
            // Through the sidebar, not by typing the URL: dayparts are shop inventory
            // like the media library, so a store user runs their own — this is not an
            // administrator's page.
            $browser->visit('/dashboard');
            $this->waitForAlpine($browser);
            $browser->waitForLink('Dayparts')
                ->waitForReload(fn (Browser $b) => $b->clickLink('Dayparts'));

            $this->waitForAlpine($browser);
            $browser->assertPathIs('/dayparts')
                ->waitForText('No dayparts found.');

            // -- Build "Deli hours", closed on Sunday --------------------------
            $this->clickAndAwait($browser, '@add-daypart',
                fn (Browser $b) => $b->waitFor('@daypart-form'));

            $browser->type('@daypart-name', 'Deli hours')
                ->value('@daypart-start', '07:00')
                ->value('@daypart-end', '20:00');

            // The inputs are driven by JS, so Alpine has to be told they changed —
            // ->value() writes the DOM without firing the events x-model listens for.
            $browser->script("['daypart-start','daypart-end'].forEach(name => {
                const el = document.querySelector('[dusk=\"' + name + '\"]');
                el.dispatchEvent(new Event('input', { bubbles: true }));
            });");

            // -- Sunday: shut ---------------------------------------------------
            // A new exception starts as CLOSED, and a closed day is asked for no
            // times at all. Two empty time boxes standing there would read as
            // something that has to be filled in.
            $browser->waitFor('@daypart-no-exceptions');
            $this->clickAndAwait($browser, '@daypart-add-exception',
                fn (Browser $b) => $b->waitFor('@daypart-exception-day-0'));

            $browser->select('@daypart-exception-day-0', '7')
                ->assertMissing('@daypart-exception-start-0')
                ->assertMissing('@daypart-exception-end-0');

            // -- Saturday: different hours --------------------------------------
            $this->clickAndAwait($browser, '@daypart-add-exception',
                fn (Browser $b) => $b->waitFor('@daypart-exception-day-1'));

            $browser->select('@daypart-exception-day-1', '6')
                ->select('@daypart-exception-mode-1', 'hours')
                ->waitFor('@daypart-exception-start-1');

            // Prefilled from the window above, so only the difference has to be
            // typed rather than the whole thing again.
            $browser->assertValue('@daypart-exception-start-1', '07:00')
                ->assertValue('@daypart-exception-end-1', '20:00');

            // -- The summary says back what was just said ----------------------
            $browser->waitForTextIn('@daypart-summary', 'Every day 7:00 AM – 8:00 PM')
                ->assertSeeIn('@daypart-summary', 'Sunday closed')
                ->assertSeeIn('@daypart-summary', 'Saturday 7:00 AM – 8:00 PM')
                ->screenshot('daypart-modal');

            $this->clickAndAwait($browser, '@daypart-save',
                fn (Browser $b) => $b->waitForText('Deli hours'));

            $this->waitForModalClosed($browser, '@daypart-form');

            // The row shows the hours and the one day that differs.
            $browser->assertSeeIn('@daypart-name-'.Daypart::firstOrFail()->id, 'Deli hours')
                ->assertSee('7:00 AM – 8:00 PM')
                ->assertSee('Sunday closed')
                ->assertSee('Active');

            $daypart = Daypart::with('exceptions')->firstOrFail();
            $this->assertSame($store->id, $daypart->store_id);
            $this->assertSame('07:00', $daypart->start_time);
            $this->assertNull($daypart->windowFor(7), 'Sunday should be closed');
            $this->assertSame(['07:00', '20:00'], $daypart->windowFor(6), 'Saturday should have its own hours');
            $this->assertSame(['07:00', '20:00'], $daypart->windowFor(3), 'Wednesday should follow the base window');

            // -- Retire it: it stays, it just leaves the pickers ---------------
            $this->clickAndAwait($browser, '@edit-daypart-'.$daypart->id,
                fn (Browser $b) => $b->waitFor('@daypart-form'));

            $this->jsClick($browser, '@daypart-retired');
            $browser->assertChecked('@daypart-retired');

            // The await polls the row itself: "Retired" also appears in the modal's own
            // checkbox label, so waiting for that text would pass before the save had
            // even left the browser.
            $this->clickAndAwait($browser, '@daypart-save',
                fn (Browser $b) => $b->waitUsing(6, 250, fn () => (bool) $daypart->fresh()->is_retired));

            $browser->waitForText('Retired');
            $this->assertTrue($daypart->fresh()->is_retired);
            $this->assertSame(0, Daypart::active()->count());
            $this->assertSame(1, Daypart::count());

            // -- Delete it, and its exception goes too -------------------------
            $this->waitForModalClosed($browser, '@daypart-form');
            $this->clickAndAwait($browser, '@delete-daypart-'.$daypart->id,
                fn (Browser $b) => $b->waitForText('Are you sure'));

            $this->clickAndAwait($browser, '@confirm-daypart-deletion-confirm',
                fn (Browser $b) => $b->waitForText('No dayparts found.'));

            $this->assertSame(0, Daypart::count());
            $this->assertDatabaseMissing('daypart_exceptions', ['daypart_id' => $daypart->id]);
        });
    }

    /**
     * A window that runs past midnight.
     *
     * The page has to tell the owner that 22:00 to 02:00 was understood as one
     * window and not as a typo, or they will "fix" it into something wrong.
     */
    public function test_a_window_that_crosses_midnight_is_labelled_as_such(): void
    {
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->owner($store);

        Daypart::factory()->overnight()->create([
            'store_id' => $store->id, 'name' => 'Late night',
        ]);

        $this->browse(function (Browser $browser) use ($owner, $store) {
            $this->freshSession($browser);
            $browser->loginAs($owner);
            $this->switchToStore($browser, $store);

            $browser->visit('/dayparts');
            $this->waitForAlpine($browser);

            $browser->waitForText('Late night')
                ->assertSee('10:00 PM – 2:00 AM')
                ->assertSee('runs past midnight')
                ->screenshot('dayparts-list');
        });
    }
}
