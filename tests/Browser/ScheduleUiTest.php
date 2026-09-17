<?php

namespace Tests\Browser;

use App\Models\Daypart;
use App\Models\Media;
use App\Models\PlaylistItem;
use App\Models\ScheduleRule;
use App\Models\Screen;
use App\Models\Store;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Building a schedule through the real pages.
 *
 * The backend tests prove the rules; this proves a shop owner can reach them. It
 * guards the class of failure a status code never shows: an Alpine method the view
 * calls but the component never defined, a nested row whose model does not bind, a
 * preview that silently reads undefined. All of those render a perfectly valid
 * page that quietly does nothing.
 */
class ScheduleUiTest extends DuskTestCase
{
    use DatabaseMigrations;

    /** A shop owner who runs their own screens, hours and playlists. */
    private function owner(Store $store): User
    {
        $this->seedSuperAdmin();

        return $this->storeMember($store, [
            'screen-view', 'screen-update', 'screen-playlist',
            'daypart-view', 'daypart-store', 'media-view',
        ]);
    }

    /**
     * The two things a screen carries for the schedule: the clock its rules are read
     * against, and the picture that fills an hour nothing was scheduled for.
     */
    public function test_an_owner_sets_a_screens_timezone_and_holding_picture(): void
    {
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->owner($store);

        $screen = Screen::factory()->create(['store_id' => $store->id, 'name' => 'Deli TV']);
        $welcome = Media::factory()->create(['store_id' => $store->id, 'title' => 'Welcome board']);

        $this->browse(function (Browser $browser) use ($owner, $store, $screen, $welcome) {
            $this->freshSession($browser);
            $browser->loginAs($owner);
            $this->switchToStore($browser, $store);

            $browser->visit('/screens');
            $this->waitForAlpine($browser);
            $browser->waitForText('Deli TV');

            // The row states the clock, because every schedule on this screen is read
            // against it.
            $browser->assertSeeIn('@screen-timezone-'.$screen->id, 'America/Chicago');

            $this->clickAndAwait($browser, '@edit-screen-'.$screen->id,
                fn (Browser $b) => $b->waitFor('@screen-form'));

            // The holding-picture list is fetched when the modal opens, so it is not
            // there the instant the modal is.
            $browser->waitUsing(10, 250, fn () => str_contains(
                $browser->text('@screen-edit-default-media'), 'Welcome board'
            ));

            $browser->select('@screen-edit-timezone', 'America/New_York')
                ->select('@screen-edit-default-media', (string) $welcome->id)
                ->screenshot('screen-settings-modal');

            $this->clickAndAwait($browser, '@screen-save',
                fn (Browser $b) => $b->waitUsing(8, 250, fn () => $screen->fresh()->default_media_id === $welcome->id));

            $this->assertSame('America/New_York', $screen->fresh()->timezone);

            $browser->waitForTextIn('@screen-timezone-'.$screen->id, 'America/New_York');
        });
    }

    /**
     * An item's own schedule: every Friday at lunchtime, with the next seven days
     * shown back before anything is committed.
     */
    public function test_an_owner_schedules_one_item_for_friday_lunchtimes(): void
    {
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->owner($store);

        $screen = Screen::factory()->create(['store_id' => $store->id, 'name' => 'Deli TV']);
        $lunch = Daypart::factory()->between('11:00', '15:00')->create([
            'store_id' => $store->id, 'name' => 'Lunch',
        ]);
        $poster = Media::factory()->create(['store_id' => $store->id, 'title' => 'Eid offer']);

        PlaylistItem::create([
            'screen_id' => $screen->id, 'media_id' => $poster->id,
            'position' => 0, 'duration_seconds' => 10,
        ]);

        $this->browse(function (Browser $browser) use ($owner, $store, $screen, $lunch) {
            $this->freshSession($browser);
            $browser->loginAs($owner);
            $this->switchToStore($browser, $store);

            $browser->visit('/screens/'.$screen->id);
            $this->waitForAlpine($browser);
            $browser->waitForText('Eid offer');

            $this->clickAndAwait($browser, '@playlist-schedule-0',
                fn (Browser $b) => $b->waitFor('@schedule-modal'));

            // Nothing set: the item plays whenever the screen is on, and the modal
            // says so rather than showing an empty box.
            $browser->waitFor('@schedule-always')
                ->assertSeeIn('@schedule-preview', 'Plays whenever the screen is on');

            $this->clickAndAwait($browser, '@schedule-add-rule',
                fn (Browser $b) => $b->waitFor('@rule-day-mode-0'));

            // WHICH DAYS: every Friday.
            $browser->select('@rule-day-mode-0', 'repeat')
                ->waitFor('@rule-type-0')
                ->select('@rule-type-0', 'weekly');

            $this->jsClick($browser, '@rule-weekday-5-0');

            // WHAT TIME: the lunch window.
            $browser->select('@rule-daypart-0', (string) $lunch->id);

            // The plain-English line, so nobody has to read four inputs to know what
            // they just said.
            $browser->waitForTextIn('@rule-summary-0', 'Friday')
                ->assertSeeIn('@rule-summary-0', 'Lunch');

            // And the preview, built by the SERVER through the same code the
            // television is answered with.
            $browser->waitUsing(10, 250, fn () => str_contains(
                $browser->text('@schedule-preview'), '11:00 AM'
            ));

            $browser->screenshot('schedule-modal');

            // OK, not Save: the schedule is staged into the playlist.
            $this->jsClick($browser, '@schedule-ok');
            $this->waitForModalClosed($browser, '@schedule-modal');

            $browser->waitForTextIn('@playlist-schedule-badge-0', 'Friday');
            $this->assertSame(0, ScheduleRule::count(), 'nothing should be stored before Save Changes');

            // The playlist's own Save Changes is what commits it, in one write.
            $this->clickAndAwait($browser, '@playlist-save',
                fn (Browser $b) => $b->waitUsing(10, 250, fn () => ScheduleRule::count() === 1));

            $rule = ScheduleRule::firstOrFail();
            $this->assertSame('weekly', $rule->recurrence_type);
            $this->assertSame([5], $rule->recurrence_weekdays);
            $this->assertSame($lunch->id, $rule->daypart_id);

            // It survives a reload, which is the only proof that matters.
            $browser->visit('/screens/'.$screen->id);
            $this->waitForAlpine($browser);
            $browser->waitForTextIn('@playlist-schedule-badge-0', 'Friday');
        });
    }

    /**
     * The payoff, on an actual television: an hour nothing is scheduled for goes dark.
     *
     * Not "No content" — a message written across a shop's screen at three in the
     * morning looks broken, and a black one looks switched off, which is what it
     * should look like. Real time rather than a mocked clock, because the server is a
     * separate process here and would not see the test's idea of "now"; the window is
     * simply put somewhere the present is not.
     */
    public function test_a_television_goes_dark_when_nothing_is_scheduled_and_comes_back_when_something_is(): void
    {
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $screen = Screen::factory()->withToken('hours-token')->create([
            'store_id' => $store->id, 'name' => 'Deli TV', 'timezone' => 'America/Chicago',
        ]);
        $poster = Media::factory()->create(['store_id' => $store->id, 'title' => 'Poster']);
        $item = PlaylistItem::create([
            'screen_id' => $screen->id, 'media_id' => $poster->id,
            'position' => 0, 'duration_seconds' => 10,
        ]);

        $there = CarbonImmutable::now($screen->timezone);

        // A window a couple of hours from now: this poster is not due at this moment.
        $later = Daypart::factory()->between(
            $there->addHours(2)->format('H:i'), $there->addHours(3)->format('H:i')
        )->create(['store_id' => $store->id, 'name' => 'Later today']);

        $rule = $item->scheduleRules()->create(['daypart_id' => $later->id]);

        $this->browse(function (Browser $tv) use ($rule, $store, $there) {
            $tv->visit('/login');
            $tv->script("localStorage.clear(); localStorage.setItem('signage.device.token', 'hours-token');");

            // -- Nothing due ---------------------------------------------------
            $tv->visit('/player');
            $tv->waitUntil("document.body.classList.contains('closed')", 30);

            // Black, and silent about it: no message, nothing playing.
            $this->assertTrue($tv->script("return document.getElementById('no-content').hidden;")[0],
                'a screen with nothing due should not be telling the shop it has no content');
            $this->assertSame('hidden', $tv->script(
                "return getComputedStyle(document.querySelector('.media-layer')).visibility;"
            )[0]);

            // -- Due -----------------------------------------------------------
            // The window is widened around the present moment, and the television is
            // switched on again.
            $now = Daypart::factory()->between(
                $there->subHours(2)->format('H:i'), $there->addHours(2)->format('H:i')
            )->create(['store_id' => $store->id, 'name' => 'Right now']);

            $rule->update(['daypart_id' => $now->id]);

            $tv->visit('/player');
            $tv->waitUntil("!document.body.classList.contains('closed')", 30);
            $tv->waitUntilMissing('@pairing-code');

            $tv->visit('/login');
            $tv->script('localStorage.clear();');
        });
    }

    /**
     * Copying a playlist onto another screen REPLACES what is there, so the panel
     * has to say what is about to be lost before the button is pressed.
     */
    public function test_copying_a_playlist_says_what_it_will_replace_first(): void
    {
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->owner($store);

        $source = Screen::factory()->create(['store_id' => $store->id, 'name' => 'Deli TV']);
        $target = Screen::factory()->create(['store_id' => $store->id, 'name' => 'Window TV']);

        $poster = Media::factory()->create(['store_id' => $store->id, 'title' => 'Eid offer']);
        $old = Media::factory()->create(['store_id' => $store->id, 'title' => 'Old notice']);

        PlaylistItem::create(['screen_id' => $source->id, 'media_id' => $poster->id, 'position' => 0, 'duration_seconds' => 10]);
        PlaylistItem::create(['screen_id' => $target->id, 'media_id' => $old->id, 'position' => 0, 'duration_seconds' => 10]);

        $this->browse(function (Browser $browser) use ($owner, $store, $source, $target, $poster) {
            $this->freshSession($browser);
            $browser->loginAs($owner);
            $this->switchToStore($browser, $store);

            $browser->visit('/screens/'.$source->id);
            $this->waitForAlpine($browser);
            $browser->waitForText('Eid offer');

            $this->clickAndAwait($browser, '@playlist-copy-open',
                fn (Browser $b) => $b->waitFor('@copy-modal'));

            // The count is the point: what Window TV is about to lose, stated before
            // the button is pressed rather than discovered afterwards.
            $browser->waitForText('Window TV')
                ->assertSee('1 item will be replaced')
                // Never a target for itself. Scoped to the modal — the page heading
                // is this screen's own name.
                ->assertDontSeeIn('@copy-modal', 'Deli TV');

            $this->jsClick($browser, '@copy-target-'.$target->id);
            $browser->waitForText('existing item(s) will be permanently replaced');

            $this->clickAndAwait($browser, '@copy-confirm',
                fn (Browser $b) => $b->waitUsing(10, 250,
                    fn () => $target->fresh()->playlistItems()->where('media_id', $poster->id)->exists()));

            $items = $target->fresh()->playlistItems;
            $this->assertCount(1, $items, 'the old playlist should have been replaced, not added to');
            $this->assertSame($poster->id, $items->first()->media_id);
        });
    }
}
