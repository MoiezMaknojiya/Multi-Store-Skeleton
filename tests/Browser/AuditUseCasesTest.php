<?php

namespace Tests\Browser;

use App\Models\BuilderAd;
use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Media;
use App\Models\Organization;
use App\Models\PlaylistItem;
use App\Models\Screen;
use App\Models\User;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Facebook\WebDriver\WebDriverKeys;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The second look after the UI/UX audit of 2026-10-13 (owner: "out of the box bhi khud se new usecase test karna takay koi
 * kasar naah rahe ... aur make sure karna k permission ho toh dikhega warna toh nahi"): what the fixes meet that nobody
 * asked about — a change typed and taken back, a question answered by keyboard, odd words in the address, a way back
 * from a page opened by its link, Undo and Save together, a connection that fails twice, a tab that is not there — and
 * a person who may only look, who is offered no button that would only be refused.
 */
class AuditUseCasesTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_a_change_typed_and_taken_back_closes_at_once_and_a_discard_answered_by_keyboard_starts_afresh(): void
    {
        [$owner, $alpha] = $this->organizationWith(['screen-view', 'screen-store', 'screen-update']);
        $screen = Screen::factory()->create(['organization_id' => $alpha->id, 'name' => 'Counter TV']);

        $this->browse(function (Browser $browser) use ($owner, $alpha, $screen) {
            $this->signIn($browser, $owner, $alpha, '/screens');
            $browser->waitFor('@edit-screen-'.$screen->id)->click('@edit-screen-'.$screen->id)->waitFor('@screen-edit-name')->pause(300);

            // Typed and taken back: nothing changed, nothing asked.
            $browser->click('@screen-edit-name');
            $this->press($browser, WebDriverKeys::END.' 2'.WebDriverKeys::BACKSPACE.WebDriverKeys::BACKSPACE);
            $this->press($browser, WebDriverKeys::ESCAPE);
            $browser->waitUntilMissing('@screen-edit-name')->assertMissing('@screen-form-modal-discard-ask');
            $this->assertSame('edit-screen-'.$screen->id, $this->focused($browser), 'the keyboard did not go back to Edit');

            // Add Screen: a code typed, Escape, Discard Changes chosen with the keyboard — and opened again, it is empty.
            $browser->click('@add-screen')->waitFor('@screen-code')->pause(600)->click('@screen-code');
            $this->press($browser, 'K7M4');
            $this->press($browser, WebDriverKeys::ESCAPE);
            $browser->waitFor('@screen-pair-modal-discard-ask');
            $this->press($browser, WebDriverKeys::TAB);
            $this->assertSame('screen-pair-modal-discard', $this->focused($browser));
            $this->press($browser, WebDriverKeys::ENTER);
            $browser->waitUntilMissing('@screen-code')->pause(300);
            $this->assertSame('add-screen', $this->focused($browser));
            $browser->click('@add-screen')->waitFor('@screen-code')->pause(400)->assertInputValue('@screen-code', '');
        });
    }

    public function test_odd_words_in_a_search_survive_the_address_and_a_reload(): void
    {
        [$owner, $alpha] = $this->organizationWith(['screen-view']);
        Screen::factory()->create(['organization_id' => $alpha->id, 'name' => 'A&B ?#100% TV']);
        Screen::factory()->create(['organization_id' => $alpha->id, 'name' => 'Kitchen TV']);

        $this->browse(function (Browser $browser) use ($owner, $alpha) {
            $this->signIn($browser, $owner, $alpha, '/screens');
            $browser->waitFor('@crud-search')->type('@crud-search', 'A&B ?#100%')->pause(1200)
                ->assertSee('A&B ?#100% TV')->assertDontSee('Kitchen TV');

            $browser->refresh();
            $this->waitForAlpine($browser);
            $browser->pause(1200);
            $this->assertSame('A&B ?#100%', $browser->value('@crud-search'));
            $browser->assertSee('A&B ?#100% TV')->assertDontSee('Kitchen TV');

            // Cleared: the address is plain again.
            $browser->keys('@crud-search', ['{control}', 'a'], '{backspace}')->pause(1200);
            $this->assertStringEndsWith('/screens', $browser->driver->getCurrentURL());
        });
    }

    public function test_a_way_back_from_a_page_opened_by_its_link_is_the_plain_link(): void
    {
        [$owner, $alpha] = $this->organizationWith(['screen-view', 'screen-playlist']);
        $screen = Screen::factory()->create(['organization_id' => $alpha->id, 'name' => 'Counter TV', 'last_seen_at' => null]);

        $this->browse(function (Browser $browser) use ($owner, $alpha, $screen) {
            // Came from the dashboard, not the list: Back to screens opens the list, never the dashboard again.
            $this->signIn($browser, $owner, $alpha, '/dashboard');
            $browser->visit('/screens/'.$screen->id)->waitFor('@back-to-screens')->click('@back-to-screens')
                ->waitForLocation('/screens')->waitFor('@crud-search')->assertPathIs('/screens');

            // Came from the list: Back to screens is the browser's own Back, and the list is as it was left.
            $browser->visit('/screens?status=offline')->waitFor('@open-screen-'.$screen->id)->click('@open-screen-'.$screen->id)
                ->waitFor('@back-to-screens')->click('@back-to-screens')->waitFor('@screens-filter-status')->pause(800);
            $this->assertStringContainsString('status=offline', $browser->driver->getCurrentURL());
        });
    }

    public function test_undo_puts_a_line_back_where_it_was_and_save_ends_it(): void
    {
        [$owner, $alpha, $screen] = $this->screenWithLines(['Breakfast', 'Lunch', 'Dinner']);

        $this->browse(function (Browser $browser) use ($owner, $alpha, $screen) {
            $this->signIn($browser, $owner, $alpha, '/screens/'.$screen->id);
            $browser->waitFor('@playlist-remove-1')->click('@playlist-remove-1')->waitFor('@playlist-removed')
                ->assertSeeIn('@playlist-removed', 'Lunch taken out.');
            $this->assertSame(['Breakfast', 'Dinner'], $this->lineTitles($browser));

            $browser->click('@playlist-undo')->waitUntilMissing('@playlist-removed');
            $this->assertSame(['Breakfast', 'Lunch', 'Dinner'], $this->lineTitles($browser));
            $browser->waitForTextIn('@announcer', 'Lunch put back at position 2.');

            // Taken out again and saved: nothing left to undo, and the screen's list has two lines.
            $browser->click('@playlist-remove-1')->waitFor('@playlist-removed')->click('@playlist-save')
                ->waitForText('Playlist saved.')->assertMissing('@playlist-removed');
            $this->assertSame(2, PlaylistItem::where('screen_id', $screen->id)->count());
        });
    }

    public function test_on_a_phone_the_save_bar_at_the_foot_saves(): void
    {
        [$owner, $alpha, $screen] = $this->screenWithLines(['Breakfast', 'Lunch']);

        $this->browse(function (Browser $browser) use ($owner, $alpha, $screen) {
            $browser->resize(375, 812);
            $this->signIn($browser, $owner, $alpha, '/screens/'.$screen->id);
            $browser->waitFor('@playlist-down-0')->assertMissing('@playlist-save-bar')
                ->click('@playlist-down-0')->waitFor('@playlist-save-bar');

            $bar = $browser->script("const r = document.querySelector('[dusk=\"playlist-save-bar\"]').getBoundingClientRect(); return [Math.round(r.bottom), innerHeight];")[0];
            $this->assertSame($bar[1], $bar[0], 'the bar is not at the foot of the window');

            $browser->click('@playlist-save-bar-button')->waitForText('Playlist saved.')->waitUntilMissing('@playlist-save-bar');
            $this->assertSame(['Lunch', 'Breakfast'], PlaylistItem::where('screen_id', $screen->id)->orderBy('position')->with('media')->get()->pluck('media.title')->all());
            $browser->resize(1920, 1080);
        });
    }

    public function test_try_again_on_a_line_still_down_stays_honest_and_loads_once_it_is_back(): void
    {
        [$owner, $alpha, $screen] = $this->screenWithLines(['Breakfast', 'Lunch']);

        $this->browse(function (Browser $browser) use ($owner, $alpha, $screen) {
            $this->signIn($browser, $owner, $alpha, '/dashboard');
            $this->block($browser, ['*/screens/'.$screen->id.'/playlist']);
            $browser->visit('/screens/'.$screen->id)->waitFor('@playlist-load-failed');

            // Still down: the same words, no line, nothing to save — and Add takes nothing onto what nobody can see.
            $browser->click('@playlist-load-failed-try-again')->pause(800)->assertVisible('@playlist-load-failed')
                ->assertMissing('@playlist-line-0')->assertDisabled('@playlist-save');

            $this->block($browser, []);
            $browser->click('@playlist-load-failed-try-again')->waitFor('@playlist-line-1');
            $this->assertSame(['Breakfast', 'Lunch'], $this->lineTitles($browser));
        });
    }

    public function test_the_arrow_keys_skip_a_tab_that_is_not_there(): void
    {
        [$owner, $alpha, $screen] = $this->screenWithLines(['Breakfast']);

        $this->browse(function (Browser $browser) use ($owner, $alpha, $screen) {
            $this->signIn($browser, $owner, $alpha, '/screens/'.$screen->id);
            $browser->waitFor('@picker-tab-library')->pause(800)->assertMissing('@picker-tab-channels');

            $browser->script("document.querySelector('[dusk=\"picker-tab-library\"]').focus()");
            $this->press($browser, WebDriverKeys::ARROW_RIGHT);
            $browser->pause(300)->assertAttribute('@picker-tab-library', 'aria-selected', 'true');
            $this->assertSame('picker-tab-library', $this->focused($browser));
        });
    }

    /**
     * Somebody who may only look (every organization page's View, nothing else) is offered no button that would only be
     * refused — the new ones of the audit included (Undo, the phone's Save bar, Try Again's lists stay theirs to read).
     */
    public function test_somebody_who_may_only_look_is_offered_nothing_to_change(): void
    {
        $this->seedSuperAdmin();
        $alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
        $viewer = $this->organizationMember($alpha, ['screen-view', 'media-view', 'ad-view', 'member-view', 'role-view', 'channel-view', 'billing-view'], 'viewer@example.com', 'Viewer');
        $screen = Screen::factory()->create(['organization_id' => $alpha->id, 'name' => 'Counter TV']);
        $media = Media::factory()->create(['organization_id' => $alpha->id, 'title' => 'Breakfast', 'thumbnail_path' => null]);
        PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $media->id, 'position' => 0, 'duration_seconds' => 10]);
        Media::factory()->create(['organization_id' => $alpha->id, 'title' => 'Lunch', 'thumbnail_path' => null]);
        $channel = Channel::factory()->create(['organization_id' => $alpha->id, 'name' => 'Our deals']);
        ChannelAd::factory()->showing(['thumbnail_path' => null])->create(['channel_id' => $channel->id, 'title' => 'Monday deal']);
        BuilderAd::factory()->create(['organization_id' => $alpha->id, 'name' => 'Spring sale']);

        $this->browse(function (Browser $browser) use ($viewer, $alpha, $screen, $channel) {
            $this->signIn($browser, $viewer, $alpha, '/dashboard');

            foreach (['/screens', '/screens/'.$screen->id, '/media', '/channels', '/channels/'.$channel->id, '/builder', '/builder/assets', '/members', '/roles', '/settings/billing'] as $page) {
                $browser->visit($page);
                $this->waitForAlpine($browser);
                $browser->pause(1200);

                $offered = $browser->script(<<<'JS'
                    const changes = /^(add|create|edit|delete|remove|upload|invite|save|save changes|pair|replace device|copy|copy to other screens|publish|unpublish|use this template|undo|schedule|rename|change role|leave|resend|revoke|adverts (on|off)|turn on|turn off)\b/i;
                    const inMain = document.getElementById('main-content');
                    return [...inMain.querySelectorAll('button, a[href], [role="button"]')]
                        .filter((el) => el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden' && !el.closest('[role="dialog"]'))
                        .map((el) => (el.innerText || el.getAttribute('aria-label') || '').trim().replace(/\s+/g, ' '))
                        .filter((words) => changes.test(words));
                JS)[0];

                // Leaving the organization is everybody's own step (rule 02), never a permission's.
                $offered = array_values(array_filter($offered, fn (string $words) => ! str_starts_with(strtolower($words), 'leave')));
                $this->assertSame([], $offered, "{$page} offers somebody who may only look: ".implode(', ', $offered));
            }
        });
    }

    /* ── The second look's review (2026-10-13): what the first fixes themselves got wrong ─────────────────────────── */

    public function test_a_field_the_page_fills_after_the_first_touch_is_no_change_of_the_persons(): void
    {
        [$owner, $alpha] = $this->organizationWith(['screen-view', 'screen-update']);
        $screen = Screen::factory()->create(['organization_id' => $alpha->id, 'name' => 'Counter TV']);

        $this->browse(function (Browser $browser) use ($owner, $alpha, $screen) {
            $this->signIn($browser, $owner, $alpha, '/screens');
            $browser->waitFor('@edit-screen-'.$screen->id)->click('@edit-screen-'.$screen->id)->waitFor('@screen-edit-name')->pause(300);

            // The person clicks into the name; the page then fills a field by itself (as the holding pictures arrive).
            $browser->click('@screen-edit-name');
            $browser->script("const t = Alpine.\$data(document.querySelector('[x-data^=\"screensTable\"]')); t.form.timezone = 'Asia/Karachi';");
            $browser->pause(300);
            $this->press($browser, WebDriverKeys::ESCAPE);
            $browser->waitUntilMissing('@screen-edit-name')->assertMissing('@screen-form-modal-discard-ask');
        });
    }

    public function test_the_list_refreshing_by_itself_leaves_an_open_dialog_alone(): void
    {
        [$owner, $alpha] = $this->organizationWith(['screen-view', 'screen-update']);
        $screen = Screen::factory()->create(['organization_id' => $alpha->id, 'name' => 'Counter TV', 'last_seen_at' => now()]);

        $this->browse(function (Browser $browser) use ($owner, $alpha, $screen) {
            $this->signIn($browser, $owner, $alpha, '/screens');
            $browser->waitFor('@edit-screen-'.$screen->id)->click('@edit-screen-'.$screen->id)->waitFor('@screen-edit-name')->pause(800);

            $this->countRequests($browser, 'GET', '/screens/'.$screen->id.'/media-options');
            $screen->forceFill(['last_seen_at' => null])->save();
            $browser->script("Alpine.\$data(document.querySelector('[x-data^=\"screensTable\"]')).refreshStatuses()");
            $browser->pause(1500);

            $this->assertSame(0, $this->requestsCounted($browser), 'the open Edit dialog loaded its pictures again');
            $this->assertFalse($browser->script("return Alpine.\$data(document.querySelector('[x-data^=\"screensTable\"]')).items[0].is_online;")[0]);
        });
    }

    public function test_a_way_back_keeps_the_owner_its_link_names_and_opens_the_list_afresh(): void
    {
        $admin = $this->seedSuperAdmin();
        $alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
        $ad = BuilderAd::factory()->create(['organization_id' => $alpha->id, 'name' => 'Alpha special']);

        $this->browse(function (Browser $browser) use ($admin, $alpha, $ad) {
            $this->freshSession($browser);
            // From the Platform's list to an ad of Alpha Mart's: Ads opens Alpha Mart's list, where the ad is.
            $browser->loginAs($admin)->visit('/builder')->waitFor('@ads-filter-organization');
            $browser->script("window.location.assign('/builder/{$ad->id}')");
            $browser->waitFor('@builder-exit')->click('@builder-exit')->waitForText('Alpha special')->pause(600);
            $this->assertSame((string) $alpha->id, $browser->value('@ads-filter-organization'));

            // From Alpha Mart's list and back: the list as it is now — an ad made meanwhile is there.
            $browser->script("window.location.assign('/builder/{$ad->id}')");
            $browser->waitFor('@builder-exit');
            BuilderAd::factory()->create(['organization_id' => $alpha->id, 'name' => 'Made meanwhile']);
            $browser->click('@builder-exit')->waitForText('Made meanwhile')->assertSee('Alpha special');
        });
    }

    public function test_a_page_past_the_last_opens_the_last_and_a_window_made_narrow_closes_the_menu(): void
    {
        [$owner, $alpha] = $this->organizationWith(['screen-view']);
        Screen::factory()->create(['organization_id' => $alpha->id, 'name' => 'Counter TV']);

        $this->browse(function (Browser $browser) use ($owner, $alpha) {
            $this->signIn($browser, $owner, $alpha, '/screens?page=7');
            $browser->waitForText('Counter TV')->assertDontSee('No screens yet.');

            // A wide window has the menu open beside the page; narrowed, it shuts, and the page is never left inert.
            $browser->resize(1440, 900)->visit('/dashboard');
            $this->waitForAlpine($browser);
            $browser->resize(800, 900)->pause(500);
            $this->assertFalse($browser->script("return document.getElementById('main-content').inert === true;")[0]);
            $this->assertSame('false', $browser->attribute('[aria-controls="main-sidebar"]', 'aria-expanded'));
            $browser->resize(1920, 1080);
        });
    }

    public function test_alt_and_an_arrow_on_a_tab_is_the_browsers_own(): void
    {
        [$owner, $alpha] = $this->organizationWith(['member-view']);

        $this->browse(function (Browser $browser) use ($owner, $alpha) {
            $this->signIn($browser, $owner, $alpha, '/members');
            $browser->waitFor('@tab-members')->pause(300);
            $prevented = $browser->script(<<<'JS'
                const tab = document.querySelector('[dusk="tab-members"]');
                tab.focus();
                const key = new KeyboardEvent('keydown', { key: 'ArrowRight', altKey: true, bubbles: true, cancelable: true });
                tab.dispatchEvent(key);
                return key.defaultPrevented;
            JS)[0];
            $this->assertFalse($prevented);
            $browser->assertAttribute('@tab-members', 'aria-selected', 'true');
        });
    }

    /* ── Setting up ────────────────────────────────────────────────────── */

    /** @return array{0: User, 1: Organization} */
    private function organizationWith(array $permissions): array
    {
        $this->seedSuperAdmin();
        $alpha = Organization::factory()->create(['name' => 'Alpha Mart']);

        return [$this->organizationMember($alpha, $permissions), $alpha];
    }

    /** @return array{0: User, 1: Organization, 2: Screen} */
    private function screenWithLines(array $titles): array
    {
        [$owner, $alpha] = $this->organizationWith(['screen-view', 'screen-playlist']);
        $screen = Screen::factory()->create(['organization_id' => $alpha->id, 'name' => 'Counter TV']);

        foreach ($titles as $position => $title) {
            $media = Media::factory()->create(['organization_id' => $alpha->id, 'title' => $title, 'thumbnail_path' => null]);
            PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $media->id, 'position' => $position, 'duration_seconds' => 10]);
        }

        return [$owner, $alpha, $screen];
    }

    private function signIn(Browser $browser, User $user, Organization $organization, string $page): void
    {
        $this->freshSession($browser);
        $browser->loginAs($user);
        $this->switchToOrganization($browser, $organization);
        $browser->visit($page);
        $this->waitForAlpine($browser);
    }

    private function press(Browser $browser, string $keys): void
    {
        $browser->driver->getKeyboard()->sendKeys($keys);
    }

    private function focused(Browser $browser): ?string
    {
        return $browser->script("return document.activeElement?.getAttribute('dusk') ?? document.activeElement?.tagName;")[0];
    }

    private function lineTitles(Browser $browser): array
    {
        return $browser->script("return [...document.querySelectorAll('[dusk^=\"playlist-title-\"]')].map((title) => title.innerText.trim());")[0];
    }

    private function block(Browser $browser, array $patterns): void
    {
        $tools = new ChromeDevToolsDriver($browser->driver);
        $tools->execute('Network.enable');
        $tools->execute('Network.setBlockedURLs', ['urls' => $patterns]);
    }
}
