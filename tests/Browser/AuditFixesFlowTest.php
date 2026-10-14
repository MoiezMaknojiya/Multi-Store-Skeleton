<?php

namespace Tests\Browser;

use App\Models\BuilderAd;
use App\Models\Invitation;
use App\Models\Media;
use App\Models\Organization;
use App\Models\PlayerAppRelease;
use App\Models\PlaylistItem;
use App\Models\Role;
use App\Models\Screen;
use App\Models\User;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Facebook\WebDriver\WebDriverKeys;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;
use Tests\Support\ApkFiles;

/**
 * The UI/UX audit of 2026-10-13, its second part: the page after a delete, names a screen reader says, the keys a tab list
 * answers, a schedule's mistake out of sight, a draft line, the library's limit, what Add says, a list that refreshes
 * under the pointer, a filter kept on the way back, a touch screen, the phone's menu, colours a control is seen by,
 * links in sentences, long names and addresses, the gallery on a phone, words that say whose list is empty, the editor's
 * bar at a small laptop, the keyboard on an invitation and in a dialog, and the TV app's version said at once.
 *
 * Each was seen failing on the code before the fix (rule 02, "a JavaScript click is not a person's click").
 */
class AuditFixesFlowTest extends DuskTestCase
{
    use DatabaseMigrations;

    /** @var list<string> */
    private array $made = [];

    protected function tearDown(): void
    {
        foreach ($this->made as $path) {
            @unlink($path);
            @rmdir(dirname($path));
        }

        parent::tearDown();
    }

    public function test_deleting_a_row_leaves_the_keyboard_on_the_row_that_took_its_place(): void
    {
        [$owner, $alpha] = $this->organizationWith(['screen-view', 'screen-destroy']);
        $first = Screen::factory()->create(['organization_id' => $alpha->id, 'name' => 'Counter TV', 'paired_at' => now()->subDays(3)]);
        $middle = Screen::factory()->create(['organization_id' => $alpha->id, 'name' => 'Kitchen TV', 'paired_at' => now()->subDays(2)]);
        $last = Screen::factory()->create(['organization_id' => $alpha->id, 'name' => 'Window TV', 'paired_at' => now()->subDay()]);

        $this->browse(function (Browser $browser) use ($owner, $alpha, $middle) {
            $this->signIn($browser, $owner, $alpha, '/screens');
            $browser->waitFor('@delete-screen-'.$middle->id);

            $this->focus($browser, 'delete-screen-'.$middle->id);
            $this->press($browser, WebDriverKeys::ENTER);
            $browser->waitFor('@confirm-screen-deletion-confirm')->pause(300)->click('@confirm-screen-deletion-confirm');
            $browser->waitUntilMissing('@delete-screen-'.$middle->id)->pause(400);

            $this->assertStringStartsWith('delete-screen-', (string) $this->focused($browser), 'the keyboard went back to the top of the page');
        });
    }

    public function test_every_button_of_a_screens_row_names_its_screen(): void
    {
        [$owner, $alpha] = $this->organizationWith(['screen-view', 'screen-store', 'screen-update', 'screen-destroy']);
        $screen = Screen::factory()->create(['organization_id' => $alpha->id, 'name' => 'Counter TV']);

        $this->browse(function (Browser $browser) use ($owner, $alpha, $screen) {
            $this->signIn($browser, $owner, $alpha, '/screens');
            $browser->waitFor('@edit-screen-'.$screen->id);

            foreach (['playlist-screen-', 'edit-screen-', 'replace-screen-', 'delete-screen-'] as $button) {
                $name = $browser->script("const b = document.querySelector('[dusk=\"{$button}{$screen->id}\"]'); return b.getAttribute('aria-label') ?? b.innerText;")[0];
                $this->assertStringContainsString('Counter TV', $name, "{$button} says nothing of which screen it acts on");
            }
        });
    }

    public function test_tab_lists_answer_the_arrow_keys_and_only_the_open_tab_is_a_stop(): void
    {
        [$owner, $alpha] = $this->organizationWith(['member-view']);

        $this->browse(function (Browser $browser) use ($owner, $alpha) {
            $this->signIn($browser, $owner, $alpha, '/members');
            $browser->waitFor('@tab-invitations')->pause(300);

            $this->assertSame(['0', '-1'], $browser->script("return ['tab-members', 'tab-invitations'].map((t) => document.querySelector('[dusk=\"' + t + '\"]').getAttribute('tabindex'));")[0]);

            $this->focus($browser, 'tab-members');
            $this->press($browser, WebDriverKeys::ARROW_RIGHT);
            $browser->pause(300)->assertAttribute('@tab-invitations', 'aria-selected', 'true');
            $this->assertSame('tab-invitations', $this->focused($browser));
            $this->assertSame(['-1', '0'], $browser->script("return ['tab-members', 'tab-invitations'].map((t) => document.querySelector('[dusk=\"' + t + '\"]').getAttribute('tabindex'));")[0]);

            $this->press($browser, WebDriverKeys::HOME);
            $browser->pause(300)->assertAttribute('@tab-members', 'aria-selected', 'true');
            $this->assertSame('tab-members', $this->focused($browser));
        });
    }

    public function test_ok_on_a_schedule_with_a_mistake_out_of_sight_puts_the_keyboard_on_it(): void
    {
        [$owner, $alpha, $screen] = $this->screenWithLines(['Breakfast']);

        $this->browse(function (Browser $browser) use ($owner, $alpha, $screen) {
            $browser->resize(1280, 640);
            $this->signIn($browser, $owner, $alpha, '/screens/'.$screen->id);
            $browser->waitFor('@playlist-schedule-0')->click('@playlist-schedule-0')->waitFor('@schedule-add-rule')->pause(300);

            // The first rule ends before it starts; three more push it out of sight above OK.
            $browser->click('@schedule-add-rule')->waitFor('@rule-day-mode-0')->select('@rule-day-mode-0', 'range');
            $browser->script(<<<'JS'
                const data = Alpine.$data(document.querySelector('[x-data^="screenPlaylist"]'));
                data.scheduleRules[0].starts_on = '2026-12-20';
                data.scheduleRules[0].ends_on = '2026-12-10';
            JS);
            foreach (range(1, 3) as $i) {
                $browser->click('@schedule-add-rule')->pause(150);
            }
            $browser->script("document.querySelector('[dusk=\"schedule-ok\"]').scrollIntoView({ block: 'end' })");
            $browser->pause(400)->click('@schedule-ok')->pause(600);

            $browser->assertSeeIn('@rule-error-0', 'The end date cannot be before the start date.');
            $this->assertSame('rule-ends-on-0', $this->focused($browser), 'OK said nothing anybody could see');
            $this->assertTrue($browser->script("const r = document.querySelector('[dusk=\"rule-ends-on-0\"]').getBoundingClientRect(); return r.top >= 0 && r.bottom <= innerHeight;")[0]);
            $browser->resize(1920, 1080);
        });
    }

    public function test_a_draft_line_keeps_its_words_readable(): void
    {
        [$owner, $alpha, $screen] = $this->screenWithLines([]);
        $draft = Media::factory()->adPage()->create(['organization_id' => $alpha->id, 'title' => 'Winter menu', 'thumbnail_path' => null]);
        BuilderAd::factory()->create(['organization_id' => $alpha->id, 'name' => 'Winter menu', 'media_id' => $draft->id, 'published_at' => null]);
        PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $draft->id, 'position' => 0, 'duration_seconds' => 10]);

        $this->browse(function (Browser $browser) use ($owner, $alpha, $screen) {
            $this->signIn($browser, $owner, $alpha, '/screens/'.$screen->id);
            $browser->waitFor('@playlist-draft-0')->assertSee('Draft');

            $faded = $browser->script(<<<'JS'
                const line = document.querySelector('[dusk="playlist-line-0"]');
                const note = document.querySelector('[dusk="playlist-draft-0"]');
                let el = note, opacity = 1;
                while (el && el !== document.body) { opacity *= parseFloat(getComputedStyle(el).opacity); el = el.parentElement; }
                return { opacity, line: parseFloat(getComputedStyle(line).opacity) };
            JS)[0];
            $this->assertSame(1.0, (float) $faded['line'], 'the whole line is faded');
            $this->assertSame(1.0, (float) $faded['opacity'], 'the words that say why are faded');
        });
    }

    public function test_the_library_says_when_it_lists_only_the_newest_and_lazy_loads_its_pictures(): void
    {
        [$owner, $alpha, $screen] = $this->screenWithLines([]);
        foreach (range(1, 101) as $i) {
            Media::factory()->create(['organization_id' => $alpha->id, 'title' => 'Poster '.$i, 'thumbnail_path' => null]);
        }

        $this->browse(function (Browser $browser) use ($owner, $alpha, $screen) {
            $this->signIn($browser, $owner, $alpha, '/screens/'.$screen->id);
            $browser->waitFor('@picker-newest-note')
                ->assertSeeIn('@picker-newest-note', 'Showing the newest 100 of 101. Search to find the rest.');

            $browser->type('@media-picker-search', 'Poster 7')->pause(900)->assertMissing('@picker-newest-note');
        });
    }

    public function test_add_in_the_library_says_added_on_the_button_at_a_desk(): void
    {
        [$owner, $alpha, $screen] = $this->screenWithLines([]);
        $poster = Media::factory()->create(['organization_id' => $alpha->id, 'title' => 'Opening poster', 'thumbnail_path' => null]);

        $this->browse(function (Browser $browser) use ($owner, $alpha, $screen, $poster) {
            $this->signIn($browser, $owner, $alpha, '/screens/'.$screen->id);
            $browser->waitFor('@playlist-add-'.$poster->id)->click('@playlist-add-'.$poster->id)->pause(200);

            $browser->assertSeeIn('@playlist-add-'.$poster->id, 'Added')
                ->waitForTextIn('@announcer', 'Opening poster added to the playlist.');
            $browser->pause(2600)->assertSeeIn('@playlist-add-'.$poster->id, 'Add');
        });
    }

    public function test_the_screens_list_refreshing_by_itself_keeps_its_rows_where_they_are(): void
    {
        [$owner, $alpha] = $this->organizationWith(['screen-view']);
        $up = Screen::factory()->create(['organization_id' => $alpha->id, 'name' => 'Counter TV', 'last_seen_at' => now()]);
        $down = Screen::factory()->create(['organization_id' => $alpha->id, 'name' => 'Kitchen TV', 'last_seen_at' => null]);

        $this->browse(function (Browser $browser) use ($owner, $alpha, $up, $down) {
            $this->signIn($browser, $owner, $alpha, '/screens');
            $browser->waitFor('@sort-status')->click('@sort-status')->pause(800);
            $before = $this->rowOrder($browser);
            $this->assertSame(['Counter TV', 'Kitchen TV'], $before);

            // Counter TV stops answering: the refresh nobody asked for says so on its row, and moves no row.
            $up->forceFill(['last_seen_at' => null])->save();
            $down->forceFill(['last_seen_at' => now()])->save();
            $browser->script("Alpine.\$data(document.querySelector('[x-data^=\"screensTable\"]')).refreshStatuses()");
            $browser->pause(1200);

            $this->assertSame($before, $this->rowOrder($browser), 'a row moved under the pointer');
            $this->assertSame([false, true], $browser->script("return Alpine.\$data(document.querySelector('[x-data^=\"screensTable\"]')).items.map((s) => s.is_online);")[0]);
        });
    }

    public function test_a_filter_is_kept_in_the_address_and_on_the_way_back_from_a_playlist(): void
    {
        [$owner, $alpha] = $this->organizationWith(['screen-view', 'screen-playlist']);
        $down = Screen::factory()->create(['organization_id' => $alpha->id, 'name' => 'Kitchen TV', 'last_seen_at' => null]);
        Screen::factory()->create(['organization_id' => $alpha->id, 'name' => 'Counter TV', 'last_seen_at' => now()]);

        $this->browse(function (Browser $browser) use ($owner, $alpha, $down) {
            $this->signIn($browser, $owner, $alpha, '/screens');
            $browser->waitFor('@screens-filter-status')->select('@screens-filter-status', 'offline')->pause(900);
            $this->assertStringContainsString('status=offline', $browser->driver->getCurrentURL());

            $browser->click('@open-screen-'.$down->id)->waitFor('@back-to-screens')->click('@back-to-screens')
                ->waitFor('@screens-filter-status')->pause(900);
            $this->assertSame('offline', $browser->value('@screens-filter-status'));
            $browser->assertSee('Kitchen TV')->assertDontSee('Counter TV');

            // Opened fresh with the address: the same list.
            $browser->visit('/screens?status=offline&search=Kitchen')->waitFor('@screens-filter-status')->pause(900);
            $this->assertSame('offline', $browser->value('@screens-filter-status'));
            $this->assertSame('Kitchen', $browser->value('@crud-search'));

            // An address no list has: the list as it always opens, never "Could not load".
            $browser->visit('/screens?status=sideways')->waitFor('@screens-filter-status')->pause(1200)
                ->assertMissing('@table-load-failed')->assertSee('Kitchen TV')->assertSee('Counter TV');
        });
    }

    public function test_the_editor_goes_back_to_the_ads_of_the_ads_owner(): void
    {
        $admin = $this->seedSuperAdmin();
        $alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
        $ad = BuilderAd::factory()->create(['organization_id' => $alpha->id, 'name' => 'Alpha special']);

        $this->browse(function (Browser $browser) use ($admin, $alpha, $ad) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/builder/'.$ad->id)->waitFor('@builder-exit');
            $this->assertStringEndsWith('/builder?owner='.$alpha->id, $browser->attribute('@builder-exit', 'href'));

            $browser->click('@builder-exit')->waitForText('Alpha special')->pause(500);
            $this->assertSame((string) $alpha->id, $browser->value('@ads-filter-organization'));
        });
    }

    public function test_on_a_touch_screen_the_buttons_are_big_enough_for_a_finger(): void
    {
        [$owner, $alpha, $screen] = $this->screenWithLines(['Breakfast', 'Lunch']);

        $this->browse(function (Browser $browser) use ($owner, $alpha, $screen) {
            $tools = new ChromeDevToolsDriver($browser->driver);
            $tools->execute('Emulation.setTouchEmulationEnabled', ['enabled' => true, 'maxTouchPoints' => 5]);
            $browser->resize(375, 812);
            $this->signIn($browser, $owner, $alpha, '/screens/'.$screen->id);
            $browser->waitFor('@playlist-up-1');

            $this->assertTrue($browser->script("return matchMedia('(pointer: coarse)').matches;")[0]);
            $small = $browser->script(<<<'JS'
                return ['playlist-up-1', 'playlist-down-0', 'playlist-remove-0', 'playlist-schedule-0', 'playlist-save']
                    .map((d) => [d, document.querySelector(`[dusk="${d}"]`).getBoundingClientRect()])
                    .filter(([, r]) => r.height < 44 || r.width < 44).map(([d, r]) => `${d} ${Math.round(r.width)}x${Math.round(r.height)}`);
            JS)[0];
            $this->assertSame([], $small, 'these are too small for a finger');

            $tools->execute('Emulation.setTouchEmulationEnabled', ['enabled' => false]);
            $browser->resize(1920, 1080);
        });
    }

    public function test_the_phone_menu_closes_on_escape_and_never_lets_the_keyboard_behind_it(): void
    {
        [$owner, $alpha] = $this->organizationWith(['screen-view']);

        $this->browse(function (Browser $browser) use ($owner, $alpha) {
            $browser->resize(375, 812);
            $this->signIn($browser, $owner, $alpha, '/dashboard');

            $browser->script("document.querySelector('[aria-controls=\"main-sidebar\"]').focus()");
            $this->press($browser, WebDriverKeys::ENTER);
            $browser->pause(400);
            $this->assertTrue($browser->script("return document.getElementById('main-sidebar').contains(document.activeElement);")[0], 'the menu opened and the keyboard stayed behind it');
            $this->assertTrue($browser->script("return document.getElementById('main-content').inert === true;")[0], 'the page behind the menu can still be tabbed into');

            $this->press($browser, WebDriverKeys::ESCAPE);
            $browser->pause(400);
            $this->assertSame('false', $browser->attribute('[aria-controls="main-sidebar"]', 'aria-expanded'));
            $this->assertTrue($browser->script("return document.activeElement === document.querySelector('[aria-controls=\"main-sidebar\"]');")[0]);
            $this->assertFalse($browser->script("return document.getElementById('main-content').inert === true;")[0]);
            $browser->resize(1920, 1080);
        });
    }

    public function test_a_switch_and_a_tick_box_stand_out_and_links_in_sentences_are_underlined(): void
    {
        $admin = $this->seedSuperAdmin();
        $alpha = Organization::factory()->create(['name' => 'Alpha Mart', 'premium_templates_unlocked' => false]);

        $this->browse(function (Browser $browser) use ($admin, $alpha) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/organizations')->waitFor('@select-organization-'.$alpha->id);

            $tick = $this->contrastOf($browser, '[dusk="select-organization-'.$alpha->id.'"]', 'borderTopColor');
            $this->assertGreaterThanOrEqual(3.0, $tick, 'an unticked box is too faint to be seen as one');

            $browser->script("document.querySelector('button[aria-label=\"Billing of Alpha Mart\"]').click()");
            $browser->waitFor('[role="switch"][aria-checked="false"]')->pause(400);
            $off = $this->contrastOf($browser, '[role="switch"][aria-checked="false"]', 'backgroundColor');
            $this->assertGreaterThanOrEqual(3.0, $off, 'a switch that is off is too faint to be seen as one');

            $browser->logout()->visit('/register')->waitForLink('Sign in');
            $this->assertStringContainsString('underline', $browser->script("return getComputedStyle([...document.querySelectorAll('a')].find((a) => a.innerText.trim() === 'Sign in')).textDecorationLine;")[0]);
        });
    }

    public function test_long_addresses_and_names_wrap_on_a_phone(): void
    {
        $this->seedSuperAdmin();
        $long = 'averyveryverylongaddressforsomebody@anorganizationwithalongdomainname.example';
        $nobody = User::factory()->create(['email' => $long]);

        $this->browse(function (Browser $browser) use ($nobody) {
            $browser->resize(375, 812);
            $this->freshSession($browser);
            $browser->loginAs($nobody)->visit('/dashboard')->waitFor('@dashboard-empty');

            // The words themselves, measured: an address with no space in it runs past its paragraph's box, not the box.
            $cut = $browser->script(<<<'JS'
                const card = document.querySelector('[dusk="dashboard-empty"]').getBoundingClientRect();
                const walker = document.createTreeWalker(document.querySelector('[dusk="dashboard-empty"]'), NodeFilter.SHOW_TEXT);
                const range = document.createRange();
                while (walker.nextNode()) {
                    range.selectNodeContents(walker.currentNode);
                    if ([...range.getClientRects()].some((r) => r.right > card.right + 1 || r.left < card.left - 1)) return walker.currentNode.textContent.trim().slice(0, 60);
                }
                return null;
            JS)[0];
            $this->assertNull($cut, 'the address runs out of its card');
            $browser->resize(1920, 1080);
        });
    }

    public function test_the_premium_gallery_on_a_phone_keeps_its_buttons_whole_and_back_in_reach(): void
    {
        $this->seedSuperAdmin();
        $alpha = Organization::factory()->create(['name' => 'Alpha Mart', 'premium_templates_unlocked' => false]);
        $designer = $this->organizationMember($alpha, ['ad-view', 'ad-store']);
        foreach (['Tall menu one', 'Tall menu two', 'Tall menu three', 'Tall menu four'] as $name) {
            BuilderAd::factory()->portrait()->published()->create(['organization_id' => null, 'name' => $name]);
        }

        $this->browse(function (Browser $browser) use ($designer, $alpha) {
            $browser->resize(375, 812);
            $this->signIn($browser, $designer, $alpha, '/builder');
            $browser->waitFor('@new-ad')->click('@new-ad')->waitFor('@new-ad-portrait')->pause(600)->click('@new-ad-portrait')
                ->waitFor('@new-ad-premium')->pause(600)->click('@new-ad-premium')->waitForText('Tall menu one')->pause(600);

            $cut = $browser->script(<<<'JS'
                return [...document.querySelectorAll('[dusk^="unlock-template-"], [dusk^="use-template-"]')]
                    .filter((b) => b.getClientRects().length > 0)
                    .filter((b) => b.scrollWidth > b.clientWidth + 1 || b.getBoundingClientRect().height > 52)
                    .map((b) => b.getAttribute('dusk'));
            JS)[0];
            $this->assertSame([], $cut, 'a template\'s button is cut short or wraps');

            $inSight = $browser->script("const r = document.querySelector('[dusk=\"templates-cancel\"]').getBoundingClientRect(); return r.bottom <= innerHeight && r.top >= 0;")[0];
            $this->assertTrue($inSight, 'Cancel is below every template, out of reach');
            $browser->resize(1920, 1080);
        });
    }

    public function test_the_ads_page_above_the_organizations_says_whose_list_is_empty(): void
    {
        $admin = $this->seedSuperAdmin();
        $alpha = Organization::factory()->create(['name' => 'Alpha Mart']);

        $this->browse(function (Browser $browser) use ($admin, $alpha) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/builder')->waitFor('@ads-filter-organization')->pause(800)
                ->assertSee('The platform has no ads yet.')
                ->assertDontSee('This organization has no ads yet.');

            $browser->select('@ads-filter-organization', (string) $alpha->id)->pause(900)
                ->assertSee('Alpha Mart has no ads yet.')
                ->assertVisible('@table-clear-filters');
        });
    }

    public function test_the_editor_bar_keeps_a_long_owner_inside_it_at_a_small_laptop(): void
    {
        $admin = $this->seedSuperAdmin();
        $long = Organization::factory()->create(['name' => 'The Very Long Organization Name Of A Chain Of Shops']);
        $ad = BuilderAd::factory()->create(['organization_id' => $long->id, 'name' => 'Spring sale']);

        $this->browse(function (Browser $browser) use ($admin, $ad) {
            $browser->resize(1100, 800);
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/builder/'.$ad->id)->waitFor('@ad-owner')->pause(600);

            $overlap = $browser->script(<<<'JS'
                const badge = document.querySelector('[dusk="ad-owner"]').getBoundingClientRect();
                const undo = [...document.querySelectorAll('button')].find((b) => /undo/i.test(b.getAttribute('aria-label') || b.title || ''));
                const u = undo ? undo.getBoundingClientRect() : null;
                return u ? badge.right > u.left : false;
            JS)[0];
            $this->assertFalse($overlap, 'the owner badge runs under Undo');
            $browser->resize(1920, 1080);
        });
    }

    public function test_declining_an_invitation_and_removing_a_membership_keep_the_keyboard(): void
    {
        $admin = $this->seedSuperAdmin();
        $alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
        $token = Str::random(64);
        Invitation::factory()->withToken($token)->create(['organization_id' => $alpha->id, 'email' => 'newcomer@example.com']);
        $staff = $this->organizationMember($alpha, Role::STAFF, 'staff@example.com');

        $this->browse(function (Browser $browser) use ($admin, $alpha, $token, $staff) {
            $this->freshSession($browser);
            $browser->visit('/invitations/'.$token)->waitFor('@invitation-decline');
            $this->focus($browser, 'invitation-decline');
            $this->press($browser, WebDriverKeys::ENTER);
            $browser->pause(300);
            $this->assertSame('invitation-decline-keep', $this->focused($browser));
            $this->press($browser, WebDriverKeys::ENTER);
            $browser->pause(300);
            $this->assertSame('invitation-decline', $this->focused($browser));

            $browser->loginAs($admin)->visit('/users')->waitFor('@manage-organizations-'.$staff->id)
                ->click('@manage-organizations-'.$staff->id)->waitFor('@membership-remove-'.$alpha->id)->pause(300);
            $this->focus($browser, 'membership-remove-'.$alpha->id);
            $this->press($browser, WebDriverKeys::ENTER);
            $browser->pause(400);
            $this->assertSame('remove-membership-password', $browser->script('return document.activeElement?.id;')[0]);
        });
    }

    public function test_the_tv_app_page_says_at_once_that_a_version_is_not_newer(): void
    {
        $admin = $this->seedSuperAdmin();
        PlayerAppRelease::create(['version_code' => 2, 'version_name' => '1.1', 'path' => 'player-app/TheDisplaySolution-1.1-2.apk', 'size' => 9_000_000, 'sha256' => str_repeat('a', 64)]);
        $old = $this->apkFile('old.apk', ApkFiles::apk(ApkFiles::manifest(versionCode: 2, versionName: '1.1')));

        $this->browse(function (Browser $browser) use ($admin, $old) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/developer')->waitFor('@tv-app-dropzone');
            $this->assertSame('status', $browser->attribute('@tv-app-status', 'role'));

            $this->uploadThrough($browser, 'tv-app', $old);
            $browser->waitForText('it must be newer than 1.1 (code 2)')
                ->assertMissing('@tv-app-version-read')
                ->assertDisabled('@tv-app-publish');
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

    private function apkFile(string $name, string $bytes): string
    {
        $folder = sys_get_temp_dir().DIRECTORY_SEPARATOR.'dusk-apk-'.bin2hex(random_bytes(4));
        mkdir($folder);
        $path = $folder.DIRECTORY_SEPARATOR.$name;
        file_put_contents($path, $bytes);
        $this->made[] = $path;

        return $path;
    }

    private function rowOrder(Browser $browser): array
    {
        return $browser->script("return [...document.querySelectorAll('[dusk^=\"open-screen-\"]')].map((a) => a.innerText.trim());")[0];
    }

    /** The contrast of an element's colour (its border or its fill) against the card it stands on. */
    private function contrastOf(Browser $browser, string $css, string $property): float
    {
        return (float) $browser->script(<<<JS
            const el = document.querySelector('{$css}');
            const canvas = document.createElement('canvas'); canvas.width = canvas.height = 1;
            const ctx = canvas.getContext('2d', { willReadFrequently: true });
            const rgb = (c) => { ctx.clearRect(0, 0, 1, 1); ctx.fillStyle = c; ctx.fillRect(0, 0, 1, 1); return [...ctx.getImageData(0, 0, 1, 1).data].slice(0, 3); };
            const lum = (c) => { const v = c.map((x) => { x /= 255; return x <= 0.04045 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4); }); return 0.2126 * v[0] + 0.7152 * v[1] + 0.0722 * v[2]; };
            let back = el.parentElement;
            while (back && getComputedStyle(back).backgroundColor === 'rgba(0, 0, 0, 0)') back = back.parentElement;
            const a = lum(rgb(getComputedStyle(el)['{$property}'])), b = lum(rgb(back ? getComputedStyle(back).backgroundColor : '#fff'));
            return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
        JS)[0];
    }

    private function focus(Browser $browser, string $dusk): void
    {
        $browser->script("document.querySelector('[dusk=\"{$dusk}\"]').focus()");
    }

    private function press(Browser $browser, string $keys): void
    {
        $browser->driver->getKeyboard()->sendKeys($keys);
    }

    private function focused(Browser $browser): ?string
    {
        return $browser->script("return document.activeElement?.getAttribute('dusk') ?? document.activeElement?.tagName;")[0];
    }
}
