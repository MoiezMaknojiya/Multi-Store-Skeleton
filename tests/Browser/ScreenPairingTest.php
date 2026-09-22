<?php

namespace Tests\Browser;

use App\Models\PairingRequest;
use App\Models\Role;
use App\Models\Screen;
use App\Models\Store;
use App\Models\User;
use App\Services\DevicePairing;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class ScreenPairingTest extends DuskTestCase
{
    use DatabaseMigrations;

    /** An owner who can do everything with screens inside one store. */
    private function makeOwner(Store $store): User
    {
        $this->seedSuperAdmin();

        return $this->storeMember($store, ['screen-view', 'screen-store', 'screen-update', 'screen-destroy']);
    }

    /**
     * The whole handshake with two real browsers: a TV showing a code, and the
     * shop owner's own panel adopting it. No admin, no typing on the TV.
     */
    public function test_a_tv_shows_a_code_and_the_owner_pairs_it_from_their_own_panel(): void
    {
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->makeOwner($store);

        $this->browse(function (Browser $tv, Browser $panel) use ($owner, $store) {
            // -- The TV boots with nothing and asks to be adopted ---------------
            // Guarantee the "with nothing" rather than hoping the previous test
            // tidied up: Dusk keeps its first browser — this one — open from one test
            // of the class to the next, so a token left in localStorage would send this
            // TV straight into playback.
            $tv->visit('/login');
            $tv->script('localStorage.clear();');
            $tv->visit('/player');
            $tv->waitFor('@pairing-code', 15);
            $tv->waitUntil('document.querySelector(\'[dusk="pairing-code"]\').textContent.trim().length === 6', 15);

            $code = trim($tv->text('@pairing-code'));
            $this->assertMatchesRegularExpression('/^[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{6}$/', $code);
            $tv->assertSee('Add Screen');

            // Reloading must NOT ask for a new code: the number the owner is halfway
            // through typing has to stay put, and the endpoint is rate limited.
            $tv->refresh();
            $tv->waitFor('@pairing-code', 15);
            $tv->waitUntil('document.querySelector(\'[dusk="pairing-code"]\').textContent.trim().length === 6', 15);
            $this->assertSame($code, trim($tv->text('@pairing-code')));
            // Scoped to THIS device on purpose. A bare PairingRequest::count()
            // also counts rows made by any other browser Dusk still has open on a
            // player page, which made this assertion fail at random. What the test
            // actually claims is narrower and stronger: this TV still has exactly
            // one pairing request, and it still carries the code on screen.
            $uuid = $tv->script("return localStorage.getItem('signage.device.uuid');")[0];
            $this->assertSame(1, PairingRequest::where('device_uuid', $uuid)->count());
            $this->assertSame($code, PairingRequest::where('device_uuid', $uuid)->value('code'));

            // -- The owner types it into their own dashboard --------------------
            $this->freshSession($panel);
            $panel->loginAs($owner);
            $this->switchToStore($panel, $store);

            $panel->visit('/screens');
            $this->waitForAlpine($panel);
            $panel->waitForText('No screens found.');

            $this->clickAndAwait($panel, '@add-screen', fn (Browser $b) => $b->waitFor('@screen-pair-form', 3));
            $this->jsType($panel, '@screen-code', $code);
            $this->jsType($panel, '@screen-name', 'Counter TV');
            $panel->select('@screen-orientation', 'portrait');
            $this->jsClick($panel, '@screen-pair-save');

            $panel->waitForText('Counter TV', 10);
            $this->waitForModalClosed($panel, '@screen-pair-form');

            $screen = Screen::where('name', 'Counter TV')->firstOrFail();
            $this->assertSame($store->id, $screen->store_id);
            $this->assertSame('portrait', $screen->orientation);

            // The device id shows under the name, so a television standing in a
            // shop can be matched to its row — the player prints the same value on
            // its own pairing screen. Its value is the point, so assert the value.
            $panel->assertSeeIn('@screen-uuid-'.$screen->id, Str::afterLast($screen->device_uuid, '-'));
            $this->assertSame($owner->id, $screen->paired_by);
            $this->assertTrue($screen->isPaired());

            // -- The TV picks the token up on its next poll and starts playing --
            // "Next poll" is up to POLL_MS (30s) away.
            $tv->waitForText('No content', 60);
            $tv->assertSee('Counter TV');

            // -- And its heartbeat turns the panel chip green -------------------
            $panel->waitUsing(60, 500, fn () => Screen::find($screen->id)?->last_seen_at !== null);
            $panel->visit('/screens');
            $this->waitForAlpine($panel);
            $panel->waitForText('Online');

            // Park the TV: a player left open keeps polling into the next test.
            $tv->visit('/login');
        });
    }

    /**
     * Every control on the screens page: cancel, edit, cancel again, replace the
     * device, search, and delete.
     */
    public function test_every_button_on_the_screens_page(): void
    {
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->makeOwner($store);

        $screen = Screen::factory()->create(['store_id' => $store->id, 'name' => 'Counter TV']);
        Screen::factory()->create(['store_id' => $store->id, 'name' => 'Window Board']);

        // A second TV is already waiting, for the replace-device flow.
        $replacementCode = app(DevicePairing::class)->register()['code'];

        $this->browse(function (Browser $browser) use ($owner, $store, $screen, $replacementCode) {
            $this->freshSession($browser);
            $browser->loginAs($owner);
            $this->switchToStore($browser, $store);

            $browser->visit('/screens');
            $this->waitForAlpine($browser);
            $browser->waitForText('Counter TV')->assertSee('Window Board');

            // -- Open Player sits beside Add Screen and points at the TV page ---
            $browser->assertVisible('@open-player')
                ->assertAttributeContains('@open-player', 'href', '/player')
                ->assertAttribute('@open-player', 'target', '_blank');

            // -- Add Screen -> Cancel closes without creating anything ----------
            $this->clickAndAwait($browser, '@add-screen', fn (Browser $b) => $b->waitFor('@screen-pair-form', 3));
            $this->jsClick($browser, '@screen-pair-cancel');
            $this->waitForModalClosed($browser, '@screen-pair-form');
            $this->assertSame(2, Screen::count());

            // -- A wrong code is refused, and still creates nothing -------------
            $this->clickAndAwait($browser, '@add-screen', fn (Browser $b) => $b->waitFor('@screen-pair-form', 3));
            $this->jsType($browser, '@screen-code', 'ZZZZZZ');
            $this->jsType($browser, '@screen-name', 'Ghost TV');
            $this->jsClick($browser, '@screen-pair-save');
            $browser->waitForText('not valid', 10);
            $this->assertSame(2, Screen::count());
            $this->jsClick($browser, '@screen-pair-cancel');
            $this->waitForModalClosed($browser, '@screen-pair-form');

            // -- Edit -> Cancel leaves the name alone ---------------------------
            $this->clickAndAwait($browser, '@edit-screen-'.$screen->id, fn (Browser $b) => $b->waitFor('@screen-form', 3));
            $this->jsType($browser, '@screen-edit-name', 'Should Not Save');
            $this->jsClick($browser, '@screen-edit-cancel');
            $this->waitForModalClosed($browser, '@screen-form');
            $this->assertSame('Counter TV', $screen->fresh()->name);

            // -- Edit -> Save renames and re-orients ----------------------------
            $this->clickAndAwait($browser, '@edit-screen-'.$screen->id, fn (Browser $b) => $b->waitFor('@screen-form', 3));
            $this->jsType($browser, '@screen-edit-name', 'Front Window');
            $browser->select('@screen-edit-orientation', 'portrait_flipped');
            $this->jsClick($browser, '@screen-save');
            $browser->waitForText('Front Window');
            $this->waitForModalClosed($browser, '@screen-form');
            $this->assertSame('portrait_flipped', $screen->fresh()->orientation);

            // -- Replace device keeps the screen and rotates the token ----------
            $tokenBefore = $screen->fresh()->token_hash;
            $this->clickAndAwait($browser, '@replace-screen-'.$screen->id, fn (Browser $b) => $b->waitFor('@screen-pair-form', 3));
            $browser->assertSee('Replace device');
            $this->jsType($browser, '@screen-code', $replacementCode);
            $this->jsClick($browser, '@screen-pair-save');
            $this->waitForModalClosed($browser, '@screen-pair-form');
            $browser->waitUsing(10, 200, fn () => Screen::find($screen->id)?->token_hash !== $tokenBefore);
            $this->assertSame(2, Screen::count());
            $this->assertSame('Front Window', $screen->fresh()->name);

            // -- Search narrows the list ----------------------------------------
            $browser->visit('/screens');
            $this->waitForAlpine($browser);
            $browser->waitForText('Front Window');
            $this->jsType($browser, '@crud-search', 'Window Board');
            $browser->waitUntilMissingText('Front Window');
            // The listing is debounced and re-fetched, so wait for the row rather
            // than asserting on whatever happens to be painted at this instant.
            $browser->waitForText('Window Board');
            $this->jsType($browser, '@crud-search', '');
            $browser->waitForText('Front Window');

            // -- Delete asks first, then removes --------------------------------
            $this->clickAndAwait($browser, '@delete-screen-'.$screen->id,
                fn (Browser $b) => $b->waitForText('Are you sure you want to delete', 3));
            $this->jsClick($browser, '@confirm-screen-deletion-confirm');
            $browser->waitUntilMissingText('Front Window');

            $this->assertDatabaseMissing('screens', ['id' => $screen->id]);
            $this->assertSame(1, Screen::count());
        });
    }

    /** A screen paired in one store is invisible from another store the same
     *  person also works in — the same wall as media. */
    public function test_screens_are_walled_per_store(): void
    {
        $alpha = Store::factory()->create(['name' => 'Alpha Mart']);
        $beta = Store::factory()->create(['name' => 'Beta Store']);
        $owner = $this->makeOwner($alpha);
        $owner->stores()->attach($beta->id, ['role_id' => Role::starter(Role::OWNER)->id]);

        Screen::factory()->create(['store_id' => $alpha->id, 'name' => 'Alpha Only TV']);

        $this->browse(function (Browser $browser) use ($owner, $alpha, $beta) {
            $this->freshSession($browser);
            $browser->loginAs($owner);

            $this->switchToStore($browser, $alpha);
            $browser->visit('/screens');
            $this->waitForAlpine($browser);
            $browser->waitForText('Alpha Only TV');

            $this->switchToStore($browser, $beta);
            $browser->visit('/screens');
            $this->waitForAlpine($browser);
            $browser->waitForText('No screens found.')
                ->assertDontSee('Alpha Only TV');
        });
    }
}
