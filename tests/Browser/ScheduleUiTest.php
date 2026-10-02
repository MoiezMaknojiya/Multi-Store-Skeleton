<?php

namespace Tests\Browser;

use App\Models\Media;
use App\Models\Organization;
use App\Models\PlaylistItem;
use App\Models\ScheduleRule;
use App\Models\Screen;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Building a schedule through the real pages.
 *
 * The backend tests prove the rules; this proves an organization owner can reach them. It
 * guards the class of failure a status code never shows: an Alpine method the view
 * calls but the component never defined, a nested row whose model does not bind, a
 * preview that silently reads undefined. All of those render a perfectly valid
 * page that quietly does nothing.
 */
class ScheduleUiTest extends DuskTestCase
{
    use DatabaseMigrations;

    /** An organization owner who runs their own screens, hours and playlists. */
    private function owner(Organization $organization): User
    {
        $this->seedSuperAdmin();

        return $this->organizationMember($organization, [
            'screen-view', 'screen-update', 'screen-playlist', 'media-view',
        ]);
    }

    /** Type into a date or a time box as a person leaving it would: the value, then `input` and `change`. */
    private function setField(Browser $browser, string $dusk, string $value): void
    {
        $browser->script(
            "const el = document.querySelector('[dusk=\"{$dusk}\"]');"
            ."el.value = '{$value}';"
            ."el.dispatchEvent(new Event('input', { bubbles: true }));"
            ."el.dispatchEvent(new Event('change', { bubbles: true }));"
        );
    }

    /** Which weekday buttons of the first rule are pressed, as their numbers (1 = Monday). */
    private function pressedWeekdays(Browser $browser): array
    {
        return $browser->script(
            'return [...document.querySelectorAll(\'[dusk^="rule-weekday-"][aria-pressed="true"]\')]'
            .'.map((button) => Number(button.getAttribute("dusk").split("-")[2]));'
        )[0];
    }

    /**
     * The two things a screen carries for the schedule: the clock its rules are read
     * against, and the picture that fills an hour nothing was scheduled for.
     */
    public function test_an_owner_sets_a_screens_timezone_and_holding_picture(): void
    {
        $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->owner($organization);

        $screen = Screen::factory()->create(['organization_id' => $organization->id, 'name' => 'Deli TV']);
        $welcome = Media::factory()->create(['organization_id' => $organization->id, 'title' => 'Welcome board']);

        $this->browse(function (Browser $browser) use ($owner, $organization, $screen, $welcome) {
            $this->freshSession($browser);
            $browser->loginAs($owner);
            $this->switchToOrganization($browser, $organization);

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
        $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->owner($organization);

        $screen = Screen::factory()->create(['organization_id' => $organization->id, 'name' => 'Deli TV']);
        $poster = Media::factory()->create(['organization_id' => $organization->id, 'title' => 'Eid offer']);

        PlaylistItem::create([
            'screen_id' => $screen->id, 'media_id' => $poster->id,
            'position' => 0, 'duration_seconds' => 10,
        ]);

        $this->browse(function (Browser $browser) use ($owner, $organization, $screen) {
            $this->freshSession($browser);
            $browser->loginAs($owner);
            $this->switchToOrganization($browser, $organization);

            // The playlist's own line, not the file's name: the Content library beside it says the name too.
            $browser->visit('/screens/'.$screen->id);
            $this->waitForAlpine($browser);
            $browser->waitFor('@playlist-schedule-0')->assertSee('Eid offer');

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

            // OK before a day is chosen: the window stays open, and says why under the rule.
            $this->jsClick($browser, '@schedule-ok');
            $browser->waitFor('@rule-error-0')
                ->assertSeeIn('@rule-error-0', 'Choose at least one day of the week.')
                ->assertVisible('@schedule-modal');

            // The two quick picks tick the usual sets at once, each in place of what was ticked.
            $this->jsClick($browser, '@rule-weekdays-0');
            $browser->waitUntilMissing('@rule-error-0', 5);
            $this->assertSame([1, 2, 3, 4, 5], $this->pressedWeekdays($browser));
            $this->jsClick($browser, '@rule-weekends-0');
            $this->assertSame([6, 7], $this->pressedWeekdays($browser));

            // Fridays alone: the weekend off again, Friday on.
            $this->jsClick($browser, '@rule-weekday-6-0');
            $this->jsClick($browser, '@rule-weekday-7-0');
            $this->jsClick($browser, '@rule-weekday-5-0');
            $this->assertSame([5], $this->pressedWeekdays($browser));

            // A repeat of sixty weeks is none the server takes: the box goes red, the reason is said under the rule,
            // and the preview says it too rather than "nothing in the next 7 days".
            $this->jsType($browser, '@rule-interval-0', '60');
            $browser->waitUsing(10, 250, fn () => str_contains($browser->text('@schedule-preview'), 'Repeat every: enter a whole number from 1 to 52.'));
            $this->jsClick($browser, '@schedule-ok');
            $browser->waitFor('@rule-error-0')->assertSeeIn('@rule-error-0', 'Repeat every: enter a whole number from 1 to 52.');
            $this->assertStringContainsString('border-red-500', (string) $browser->attribute('@rule-interval-0', 'class'));

            $this->jsType($browser, '@rule-interval-0', '1');
            $browser->waitUntilMissing('@rule-error-0', 5);

            // WHAT TIME: typed here, on the rule (owner, 2026-10-01). "Between times" with none typed is refused under
            // the rule, and so is the same time twice.
            $browser->select('@rule-time-mode-0', 'times')->waitFor('@rule-start-time-0');
            $this->jsClick($browser, '@schedule-ok');
            $browser->waitFor('@rule-error-0')->assertSeeIn('@rule-error-0', 'Time: give both a start and an end, or choose All day.');
            $this->assertStringContainsString('border-red-500', (string) $browser->attribute('@rule-start-time-0', 'class'));

            $this->setField($browser, 'rule-start-time-0', '11:00');
            $this->setField($browser, 'rule-end-time-0', '11:00');
            $this->jsClick($browser, '@schedule-ok');
            $browser->waitFor('@rule-error-0')->assertSeeIn('@rule-error-0', 'the start and the end cannot be the same');

            // An end before the start runs past midnight, and the window says so.
            $this->setField($browser, 'rule-start-time-0', '22:00');
            $this->setField($browser, 'rule-end-time-0', '02:00');
            $browser->waitFor('@rule-past-midnight-0')->assertSeeIn('@rule-summary-0', 'past midnight');

            // Lunchtime.
            $this->setField($browser, 'rule-start-time-0', '11:00');
            $this->setField($browser, 'rule-end-time-0', '15:00');
            $browser->waitUntilMissing('@rule-past-midnight-0', 5)->waitUntilMissing('@rule-error-0', 5);

            // The plain-English line, so nobody has to read four inputs to know what
            // they just said.
            $browser->waitForTextIn('@rule-summary-0', 'Friday')
                ->assertSeeIn('@rule-summary-0', '11:00 AM – 3:00 PM');

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
            $this->assertSame(['11:00', '15:00'], [$rule->start_time, $rule->end_time]);

            // It survives a reload, which is the only proof that matters.
            $browser->visit('/screens/'.$screen->id);
            $this->waitForAlpine($browser);
            $browser->waitForTextIn('@playlist-schedule-badge-0', 'Friday');
        });
    }

    /**
     * The payoff, on an actual television: an hour nothing is scheduled for goes dark.
     *
     * Not "No content" — a message written across an organization's screen at three in the
     * morning looks broken, and a black one looks switched off, which is what it
     * should look like. Real time rather than a mocked clock, because the server is a
     * separate process here and would not see the test's idea of "now"; the window is
     * simply put somewhere the present is not.
     */
    public function test_a_television_goes_dark_when_nothing_is_scheduled_and_comes_back_when_something_is(): void
    {
        $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
        $screen = Screen::factory()->withToken('hours-token')->create([
            'organization_id' => $organization->id, 'name' => 'Deli TV', 'timezone' => 'America/Chicago',
        ]);
        // A real picture on the Dusk disk: coming back is proved by the poster being on
        // screen, and a factory row's file does not exist.
        $poster = Media::factory()->create([
            'organization_id' => $organization->id, 'title' => 'Poster', 'mime_type' => 'image/png',
            'path' => $this->putImage("media/{$organization->id}/hours-poster.png", 40, 160, 90),
            'thumbnail_path' => null,
        ]);
        $item = PlaylistItem::create([
            'screen_id' => $screen->id, 'media_id' => $poster->id,
            'position' => 0, 'duration_seconds' => 10,
        ]);

        $there = CarbonImmutable::now($screen->timezone);

        // Hours a couple of hours from now: this poster is not due at this moment.
        $rule = $item->scheduleRules()->create([
            'start_time' => $there->addHours(2)->format('H:i'), 'end_time' => $there->addHours(3)->format('H:i'),
        ]);

        $this->browse(function (Browser $tv) use ($rule, $there) {
            $tv->visit('/login');
            $tv->script("localStorage.clear(); localStorage.setItem('signage.device.token', 'hours-token');");

            // -- Nothing due ---------------------------------------------------
            $tv->visit('/player');
            $tv->waitUntil("document.body.classList.contains('closed')", 30);

            // Black, and silent about it: no message, nothing playing.
            $this->assertTrue($tv->script("return document.getElementById('no-content').hidden;")[0],
                'a screen with nothing due should not be telling the organization it has no content');
            $this->assertSame('hidden', $tv->script(
                "return getComputedStyle(document.querySelector('.media-layer')).visibility;"
            )[0]);

            // -- Due -----------------------------------------------------------
            // The hours are widened around the present moment, and the television is
            // switched on again.
            $rule->update(['start_time' => $there->subHours(2)->format('H:i'), 'end_time' => $there->addHours(2)->format('H:i')]);

            $tv->visit('/player');

            // A fresh page has no 'closed' class until its first manifest says so, so looking
            // for its absence alone would pass before the player had asked anything. The
            // poster itself, revealed on a content layer, is what proves the server said "due".
            $tv->waitUsing(30, 200, fn () => $tv->script(
                'return !!document.querySelector("#layer-a:not([hidden]) img, #layer-b:not([hidden]) img");'
            )[0]);

            $this->assertFalse($tv->script("return document.body.classList.contains('closed');")[0],
                'the poster is due, yet the screen is still dark');
            $this->assertSame('visible', $tv->script(
                "return getComputedStyle(document.querySelector('.media-layer')).visibility;"
            )[0]);
            $tv->assertMissing('@pairing-code');

            $tv->visit('/login');
            $tv->script('localStorage.clear();');
        });
    }

    /**
     * One schedule on several lines without typing it again, and a line whose schedule is over says so (owner,
     * 2026-10-01): the reuse a named daypart used to give, and the mark a line that plays no more was missing.
     */
    public function test_a_schedule_is_copied_to_other_lines_and_one_that_is_over_says_ended(): void
    {
        $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->owner($organization);
        $screen = Screen::factory()->create(['organization_id' => $organization->id, 'name' => 'Deli TV']);

        foreach (['Eid offer', 'Winter sale', 'Coffee deal'] as $position => $title) {
            PlaylistItem::create([
                'screen_id' => $screen->id, 'position' => $position, 'duration_seconds' => 10,
                'media_id' => Media::factory()->create(['organization_id' => $organization->id, 'title' => $title])->id,
            ]);
        }

        $this->browse(function (Browser $browser) use ($owner, $organization, $screen) {
            $this->freshSession($browser);
            $browser->loginAs($owner);
            $this->switchToOrganization($browser, $organization);

            $browser->visit('/screens/'.$screen->id);
            $this->waitForAlpine($browser);
            // The playlist's own last line, not the name: the Content library beside it lists the same files,
            // and its answer can come first.
            $browser->waitFor('@playlist-schedule-2')->assertMissing('@playlist-ended-0');

            // Leaving the page asks only while something waits for Save Changes.
            $leavingAsks = fn (): bool => $browser->script(
                'const leaving = new Event("beforeunload", { cancelable: true }); window.dispatchEvent(leaving); return leaving.defaultPrevented;'
            )[0];
            $this->assertFalse($leavingAsks(), 'nothing was changed, yet leaving the page asks');

            // The first line: three days long ago, at lunchtime.
            $this->clickAndAwait($browser, '@playlist-schedule-0', fn (Browser $b) => $b->waitFor('@schedule-modal'));
            $this->clickAndAwait($browser, '@schedule-add-rule', fn (Browser $b) => $b->waitFor('@rule-day-mode-0'));
            $browser->select('@rule-day-mode-0', 'range')->waitFor('@rule-starts-on-0');
            $this->setField($browser, 'rule-starts-on-0', '2020-03-20');
            $this->setField($browser, 'rule-ends-on-0', '2020-03-22');
            $browser->select('@rule-time-mode-0', 'times')->waitFor('@rule-start-time-0');
            $this->setField($browser, 'rule-start-time-0', '11:00');
            $this->setField($browser, 'rule-end-time-0', '15:00');

            // …and the third line as well. The list offers the other lines, never this one.
            $browser->assertMissing('@schedule-copy-panel');
            $this->jsClick($browser, '@schedule-copy-open');
            $browser->waitFor('@schedule-copy-panel')
                ->assertSeeIn('@schedule-copy-panel', 'Winter sale')
                ->assertSeeIn('@schedule-copy-panel', 'Coffee deal')
                ->assertDontSeeIn('@schedule-copy-panel', 'Eid offer');

            // Select All ticks every other line and becomes Clear All; pressed again, none is ticked.
            $this->jsClick($browser, '@schedule-copy-all');
            $browser->waitForTextIn('@schedule-copy-all', 'Clear All')
                ->assertChecked('@schedule-copy-line-2')->assertChecked('@schedule-copy-line-3');
            $this->jsClick($browser, '@schedule-copy-all');
            $browser->waitForTextIn('@schedule-copy-all', 'Select All')
                ->assertNotChecked('@schedule-copy-line-2')->assertNotChecked('@schedule-copy-line-3');

            $this->jsClick($browser, '@schedule-copy-line-3');

            $this->jsClick($browser, '@schedule-ok');
            $this->waitForModalClosed($browser, '@schedule-modal');
            $browser->waitForText('Schedule copied to 1 other line.');
            $this->assertTrue($leavingAsks(), 'two lines wait for Save Changes, and leaving the page does not ask');

            // Both say when they played, and that it is over; the line between them was left alone.
            $browser->waitFor('@playlist-ended-0')
                ->assertVisible('@playlist-ended-2')
                ->assertSeeIn('@playlist-schedule-badge-2', '11:00 AM – 3:00 PM')
                ->assertMissing('@playlist-ended-1')
                ->assertMissing('@playlist-schedule-badge-1');
            $this->assertSame(0, ScheduleRule::count(), 'nothing should be stored before Save Changes');

            // Save Changes commits both, each line a rule of its own.
            $this->clickAndAwait($browser, '@playlist-save',
                fn (Browser $b) => $b->waitUsing(10, 250, fn () => ScheduleRule::count() === 2));

            $lines = PlaylistItem::with('scheduleRules')->where('screen_id', $screen->id)->orderBy('position')->get();
            $this->assertSame([1, 0, 1], $lines->map(fn (PlaylistItem $line) => $line->scheduleRules->count())->all());

            foreach ([$lines[0], $lines[2]] as $line) {
                $rule = $line->scheduleRules->first();
                $this->assertSame(['11:00', '15:00', '2020-03-22'], [$rule->start_time, $rule->end_time, $rule->ends_on->toDateString()]);
            }

            // Saved: nothing is waiting any more.
            $browser->waitUsing(5, 100, fn () => ! $leavingAsks(), 'the playlist is saved, and leaving the page still asks');

            // After a reload the mark is still there: it is read from the saved rules and the screen's own today.
            $browser->visit('/screens/'.$screen->id);
            $this->waitForAlpine($browser);
            $browser->waitFor('@playlist-ended-0')->assertVisible('@playlist-ended-2')->assertMissing('@playlist-ended-1');

            // A schedule with no last day never ends: the mark goes as soon as the dates do.
            $this->clickAndAwait($browser, '@playlist-schedule-0', fn (Browser $b) => $b->waitFor('@schedule-modal'));
            $browser->select('@rule-day-mode-0', 'always');
            $this->jsClick($browser, '@schedule-ok');
            $this->waitForModalClosed($browser, '@schedule-modal');
            $browser->waitUntilMissing('@playlist-ended-0', 5)->assertVisible('@playlist-ended-2');
        });
    }

    /**
     * Copying a playlist onto another screen REPLACES what is there, so the panel
     * has to say what is about to be lost before the button is pressed.
     */
    public function test_copying_a_playlist_says_what_it_will_replace_first(): void
    {
        $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->owner($organization);

        $source = Screen::factory()->create(['organization_id' => $organization->id, 'name' => 'Deli TV']);
        $target = Screen::factory()->create(['organization_id' => $organization->id, 'name' => 'Window TV']);

        $poster = Media::factory()->create(['organization_id' => $organization->id, 'title' => 'Eid offer']);
        $old = Media::factory()->create(['organization_id' => $organization->id, 'title' => 'Old notice']);

        PlaylistItem::create(['screen_id' => $source->id, 'media_id' => $poster->id, 'position' => 0, 'duration_seconds' => 10]);
        PlaylistItem::create(['screen_id' => $target->id, 'media_id' => $old->id, 'position' => 0, 'duration_seconds' => 10]);

        $this->browse(function (Browser $browser) use ($owner, $organization, $source, $target, $poster) {
            $this->freshSession($browser);
            $browser->loginAs($owner);
            $this->switchToOrganization($browser, $organization);

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
