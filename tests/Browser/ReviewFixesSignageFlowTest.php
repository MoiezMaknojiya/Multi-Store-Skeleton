<?php

namespace Tests\Browser;

use App\Models\BuilderAd;
use App\Models\Media;
use App\Models\Organization;
use App\Models\PairingRequest;
use App\Models\PlaylistItem;
use App\Models\Role;
use App\Models\ScheduleRule;
use App\Models\Screen;
use App\Models\User;
use Carbon\CarbonInterface;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The signage findings of the review of 2026-10-15, where a person meets them: a repeat that keeps the day it counts
 * from, and one that ended long ago saving back as it came, Select All for Copy to Other Lines at a double click, the
 * playlist's time leaving a draft out, the Content Library's poster of an ad page, a slow pairing answer and the dialog
 * opened after it, Adverts On for every screen of the organization, and a television whose own clock calls its code
 * expired while its token waits on the server.
 *
 * Pressed as a person presses — presses sent to Chrome itself (Input.dispatchMouseEvent) with the click count a double
 * click carries — and on a slow line where the answer's lateness is the point (DuskTestCase::network()).
 */
class ReviewFixesSignageFlowTest extends DuskTestCase
{
    use DatabaseMigrations;

    /** An owner of the organization's screens and playlists. */
    private function owner(Organization $organization): User
    {
        $this->seedSuperAdmin();

        return $this->organizationMember($organization, [
            'screen-view', 'screen-store', 'screen-update', 'screen-playlist', 'media-view',
        ]);
    }

    public function test_a_fortnightly_repeat_with_no_start_keeps_the_day_it_counts_from_through_every_save(): void
    {
        $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->owner($organization);
        $screen = Screen::factory()->create(['organization_id' => $organization->id, 'name' => 'Deli TV', 'timezone' => 'America/Chicago']);
        $poster = Media::factory()->create(['organization_id' => $organization->id, 'title' => 'Eid offer']);
        $menu = Media::factory()->create(['organization_id' => $organization->id, 'title' => 'Menu']);
        PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $poster->id, 'position' => 0, 'duration_seconds' => 10]);
        PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $menu->id, 'position' => 1, 'duration_seconds' => 10]);
        // The Monday of the screen's own week: a fortnightly repeat counts its weeks from it.
        $counted = $screen->localTime()->startOfWeek(CarbonInterface::MONDAY)->toDateString();

        $this->browse(function (Browser $browser) use ($owner, $organization, $screen, $counted) {
            $this->freshSession($browser);
            $browser->loginAs($owner);
            $this->switchToOrganization($browser, $organization);
            $browser->visit('/screens/'.$screen->id);
            $this->waitForAlpine($browser);
            $browser->waitFor('@playlist-schedule-0');

            // Every 2 weeks on Monday, Starting left empty.
            $this->clickAndAwait($browser, '@playlist-schedule-0', fn (Browser $b) => $b->waitFor('@schedule-modal'));
            $this->clickAndAwait($browser, '@schedule-add-rule', fn (Browser $b) => $b->waitFor('@rule-day-mode-0'));
            $browser->select('@rule-day-mode-0', 'repeat')->waitFor('@rule-type-0')->select('@rule-type-0', 'weekly');
            $this->jsType($browser, '@rule-interval-0', '2');
            $this->jsClick($browser, '@rule-weekday-1-0');
            $this->assertSame('', $browser->inputValue('@rule-repeat-start-0'));
            $this->jsClick($browser, '@schedule-ok');
            $this->waitForModalClosed($browser, '@schedule-modal');

            $this->clickAndAwait($browser, '@playlist-save', fn (Browser $b) => $b->waitUsing(10, 250, fn () => ScheduleRule::count() === 1));
            $this->assertSame($counted, ScheduleRule::sole()->starts_on->toDateString(), 'the screen’s own week, never the server’s');

            // The window says the day now: the page holds the answer the save gave.
            $this->clickAndAwait($browser, '@playlist-schedule-0', fn (Browser $b) => $b->waitFor('@rule-repeat-start-0'));
            $this->assertSame($counted, $browser->inputValue('@rule-repeat-start-0'));
            $this->jsClick($browser, '@schedule-cancel');
            $this->waitForModalClosed($browser, '@schedule-modal');

            // A colleague's kind of save — the other line retimed — keeps it.
            $this->jsType($browser, '@playlist-duration-1', '20');
            $this->clickAndAwait($browser, '@playlist-save', fn (Browser $b) => $b->waitUsing(10, 250, fn () => PlaylistItem::where('position', 1)->value('duration_seconds') === 20));
            $this->assertSame($counted, ScheduleRule::sole()->starts_on->toDateString());
        });
    }

    public function test_a_repeat_that_ended_before_its_rules_were_last_written_saves_back_untouched(): void
    {
        $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->owner($organization);
        $screen = Screen::factory()->create(['organization_id' => $organization->id, 'name' => 'Deli TV', 'timezone' => 'America/Chicago']);
        $poster = Media::factory()->create(['organization_id' => $organization->id, 'title' => 'Eid offer']);
        $menu = Media::factory()->create(['organization_id' => $organization->id, 'title' => 'Menu']);
        $line = PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $poster->id, 'position' => 0, 'duration_seconds' => 10]);
        PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $menu->id, 'position' => 1, 'duration_seconds' => 10]);

        // Every 2 weeks on Monday, over two weeks ago — its rule row written by the playlist's last save, three days ago.
        $written = now()->subDays(3)->format('Y-m-d H:i:s');
        DB::table('schedule_rules')->insert([
            'playlist_item_id' => $line->id, 'recurrence_type' => ScheduleRule::WEEKLY, 'recurrence_interval' => 2,
            'recurrence_weekdays' => json_encode([1]), 'recurrence_until' => now()->subDays(14)->toDateString(), 'position' => 0,
            'created_at' => $written, 'updated_at' => $written,
        ]);

        $this->browse(function (Browser $browser) use ($owner, $organization, $screen) {
            $this->freshSession($browser);
            $browser->loginAs($owner);
            $this->switchToOrganization($browser, $organization);
            $browser->visit('/screens/'.$screen->id);
            $this->waitForAlpine($browser);
            $browser->waitFor('@playlist-duration-1');

            // A colleague retimes the other line and saves: the ended repeat goes back as it came, and the save lands.
            $this->jsType($browser, '@playlist-duration-1', '20');
            $this->clickAndAwait($browser, '@playlist-save', fn (Browser $b) => $b->waitUsing(10, 250, fn () => PlaylistItem::where('position', 1)->value('duration_seconds') === 20));
            $browser->assertDontSee('The repeat cannot end before the schedule starts.');
            $this->assertNull(ScheduleRule::sole()->starts_on);
        });
    }

    public function test_select_all_for_copy_to_other_lines_turns_once_at_a_double_click(): void
    {
        $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->owner($organization);
        $screen = Screen::factory()->create(['organization_id' => $organization->id, 'name' => 'Deli TV']);
        foreach (['Eid offer', 'Menu', 'Breakfast'] as $position => $title) {
            $media = Media::factory()->create(['organization_id' => $organization->id, 'title' => $title]);
            PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $media->id, 'position' => $position, 'duration_seconds' => 10]);
        }

        $this->browse(function (Browser $browser) use ($owner, $organization, $screen) {
            $this->freshSession($browser);
            $browser->loginAs($owner);
            $this->switchToOrganization($browser, $organization);
            $browser->visit('/screens/'.$screen->id);
            $this->waitForAlpine($browser);
            // The playlist's lines arrive after the page: its schedule button is not there before them.
            $browser->waitFor('@playlist-schedule-0');

            $this->clickAndAwait($browser, '@playlist-schedule-0', fn (Browser $b) => $b->waitFor('@schedule-modal'));
            $this->clickAndAwait($browser, '@schedule-copy-open', fn (Browser $b) => $b->waitFor('@schedule-copy-all'));

            // A mouse that sends two: one press selects every other line, and the second does not clear them again.
            $this->presses($browser, $this->centreOf($browser, '[dusk="schedule-copy-all"]'), 2);
            $browser->pause(300);
            $this->assertSame([true, true], $browser->script(
                'return [...document.querySelectorAll(\'[dusk^="schedule-copy-line-"]\')].map((box) => box.checked);'
            )[0]);
            $browser->assertSeeIn('@schedule-copy-all', 'Clear All');

            // Two presses apart still turn it and back.
            $this->presses($browser, $this->centreOf($browser, '[dusk="schedule-copy-all"]'), 1);
            $browser->waitForTextIn('@schedule-copy-all', 'Select All');
        });
    }

    public function test_the_playlists_time_counts_what_plays_and_leaves_a_draft_out(): void
    {
        $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->owner($organization);
        $screen = Screen::factory()->create(['organization_id' => $organization->id, 'name' => 'Deli TV']);
        $poster = Media::factory()->create(['organization_id' => $organization->id, 'title' => 'Eid offer']);
        $ad = BuilderAd::factory()->published()->create(['organization_id' => $organization->id, 'name' => 'Winter sale']);
        $ad->forceFill(['published_at' => null])->save();
        PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $poster->id, 'position' => 0, 'duration_seconds' => 10]);
        PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $ad->media_id, 'position' => 1, 'duration_seconds' => 15]);

        $this->browse(function (Browser $browser) use ($owner, $organization, $screen) {
            $this->freshSession($browser);
            $browser->loginAs($owner);
            $this->switchToOrganization($browser, $organization);
            $browser->visit('/screens/'.$screen->id);
            $this->waitForAlpine($browser);

            $browser->waitFor('@playlist-draft-badge-1')
                // Before: "2 items · 1 playing · 20 secs" — the draft's own seconds counted with the poster's.
                ->waitForTextIn('@playlist-summary', '2 items · 1 playing · 10 secs');
        });
    }

    public function test_the_content_librarys_poster_of_an_ad_page_carries_the_version_of_its_last_publish(): void
    {
        $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->owner($organization);
        $screen = Screen::factory()->create(['organization_id' => $organization->id, 'name' => 'Deli TV']);
        $ad = BuilderAd::factory()->withText()->published()->create(['organization_id' => $organization->id, 'name' => 'Winter sale']);
        $ad->media->forceFill(['thumbnail_path' => "builder/{$organization->id}/ads/{$ad->id}/poster.jpg"])->save();
        // Published again a day later: its poster is redrawn under the same name.
        $ad->media->forceFill(['updated_at' => now()->addDay()])->save();
        $version = '?v='.$ad->media->fresh()->updated_at->getTimestamp();

        $this->browse(function (Browser $browser) use ($owner, $organization, $screen, $version) {
            $this->freshSession($browser);
            $browser->loginAs($owner);
            $this->switchToOrganization($browser, $organization);
            $browser->visit('/screens/'.$screen->id);
            $this->waitForAlpine($browser);
            $browser->waitForTextIn('@picker-list', 'Winter sale');

            // Before: "?v=0" on every ad page in the list — a browser kept last week's poster.
            $this->assertStringEndsWith($version, $browser->attribute('[dusk="picker-list"] img', 'src'));
        });
    }

    public function test_a_slow_pairing_answer_leaves_the_dialog_opened_after_it_alone(): void
    {
        $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->owner($organization);
        $counter = Screen::factory()->create(['organization_id' => $organization->id, 'name' => 'Counter TV']);

        $this->browse(function (Browser $browser) use ($owner, $organization, $counter) {
            $this->freshSession($browser);
            $browser->loginAs($owner);
            $this->switchToOrganization($browser, $organization);
            $browser->visit('/screens');
            $this->waitForAlpine($browser);
            $browser->waitForText('Counter TV');

            // A code nobody's television shows, sent on a slow line.
            $this->clickAndAwait($browser, '@add-screen', fn (Browser $b) => $b->waitFor('@screen-pair-form'));
            $this->jsType($browser, '@screen-code', 'ZZZZZZ');
            $this->jsType($browser, '@screen-name', 'Window TV');
            $this->network($browser, latency: 3000);
            $this->jsClick($browser, '@screen-pair-save');

            // Shut before the answer, and Replace Device opened on the row: another code being typed.
            $this->jsClick($browser, '@screen-pair-cancel');
            $this->waitForModalClosed($browser, '@screen-pair-form');
            $this->clickAndAwait($browser, '@replace-screen-'.$counter->id, fn (Browser $b) => $b->waitFor('@screen-pair-form'));
            $browser->waitForText('Replacing the device for');
            $this->jsType($browser, '@screen-code', 'ABCDEF');

            // The refusal of the first is said in a toast, and the Replace Device dialog stays as it is.
            $browser->waitForText('That code is not valid any more', 15);
            $this->network($browser);
            $browser->assertVisible('@screen-pair-form')
                ->assertSee('Replacing the device for')
                ->assertInputValue('@screen-code', 'ABCDEF');
            $this->assertSame([], $browser->script(
                'return [...document.querySelectorAll(\'[dusk="screen-pair-form"] .form-error\')].map((error) => error.textContent.trim()).filter(Boolean);'
            )[0], 'nothing of the first answer under the second dialog’s code');
        });
    }

    public function test_adverts_on_for_every_screen_reaches_every_screen_of_the_organization(): void
    {
        $admin = $this->seedSuperAdmin();
        $organization = Organization::factory()->create(['name' => 'Alpha Mart', 'accepts_network_ads' => true]);
        $owner = $this->organizationMember($organization, Role::OWNER);
        // More than a page of them, and a search that leaves one in sight.
        Screen::factory()->count(54)->create(['organization_id' => $organization->id]);
        Screen::factory()->create(['organization_id' => $organization->id, 'name' => 'Counter TV']);
        $theirs = Screen::factory()->create(['organization_id' => Organization::factory()->create()->id]);

        $this->browse(function (Browser $browser) use ($admin, $owner, $organization, $theirs) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/users');
            $this->waitForAlpine($browser);
            $browser->waitForText($owner->email);
            $browser->waitForReload(fn (Browser $b) => $this->jsClick($b, '@impersonate-'.$owner->id));
            $this->switchToOrganization($browser, $organization);

            $browser->visit('/screens?search=Counter');
            $this->waitForAlpine($browser);
            $browser->waitFor('@all-screens-ads-on')->waitForText('Counter TV');

            $this->presses($browser, $this->centreOf($browser, '[dusk="all-screens-ads-on"]'), 1);
            $browser->waitUsing(10, 250, fn () => Screen::where('organization_id', $organization->id)->where('accepts_network_ads', true)->count() === 55);
            $browser->waitForText('Updated 55 screens');
            $this->assertFalse($theirs->fresh()->accepts_network_ads);

            $this->presses($browser, $this->centreOf($browser, '[dusk="all-screens-ads-off"]'), 1);
            $browser->waitUsing(10, 250, fn () => Screen::where('accepts_network_ads', true)->doesntExist());
        });
    }

    public function test_a_television_whose_clock_calls_its_code_expired_collects_the_token_waiting_for_it(): void
    {
        $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
        $screen = Screen::factory()->unpaired()->create(['organization_id' => $organization->id, 'name' => 'Counter TV']);

        // The owner typed the code while the set was off; it comes back twenty minutes on, its code past its time.
        $uuid = (string) Str::uuid();
        $secret = Str::random(40);
        $token = Str::random(64);
        PairingRequest::create([
            'device_uuid' => $uuid, 'code' => 'K7M4XQ', 'poll_secret_hash' => hash('sha256', $secret),
            'expires_at' => now()->subMinutes(5), 'claimed_screen_id' => $screen->id, 'claimed_token' => $token,
        ]);
        $screen->forceFill(['token_hash' => hash('sha256', $token), 'device_uuid' => $uuid, 'paired_at' => now()])->save();

        $this->browse(function (Browser $tv) use ($uuid, $secret, $token) {
            $tv->visit('/login');
            $tv->script('localStorage.clear();'
                ."localStorage.setItem('signage.device.uuid', '{$uuid}');"
                ."localStorage.setItem('signage.device.poll_secret', '{$secret}');"
                ."localStorage.setItem('signage.device.code', 'K7M4XQ');"
                ."localStorage.setItem('signage.device.code_expires', String(Date.now() - 300000));");

            // Before: the player registered again in place of asking, and the token waiting for it was thrown away.
            $tv->visit('/player');
            $tv->waitForText('No content', 20)->assertSee('Counter TV');
            $this->assertSame($token, $tv->script("return localStorage.getItem('signage.device.token');")[0]);
            $this->assertFalse(PairingRequest::where('device_uuid', $uuid)->exists(), 'collected, and the request gone');

            // Park the TV: a player left open keeps polling into the next test.
            $tv->visit('/login');
            $tv->script('localStorage.clear();');
        });
    }

    /** Presses sent to Chrome itself, with the click count a double or triple click carries (BillingFlowTest's own). */
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

    private function centreOf(Browser $browser, string $css): array
    {
        return $browser->script('const el = document.querySelector('.json_encode($css).'); el.scrollIntoView({ block: "center" }); '
            .'const r = el.getBoundingClientRect(); return [Math.round(r.left + r.width / 2), Math.round(r.top + r.height / 2)];')[0];
    }
}
