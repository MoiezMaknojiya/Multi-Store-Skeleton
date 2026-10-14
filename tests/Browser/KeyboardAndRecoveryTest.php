<?php

namespace Tests\Browser;

use App\Models\BuilderAd;
use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Media;
use App\Models\Organization;
use App\Models\PlaylistItem;
use App\Models\Role;
use App\Models\Screen;
use App\Models\User;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Facebook\WebDriver\WebDriverKeys;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The UI/UX audit of 2026-10-13, its first part: what a keyboard, a screen reader, a hand that misses the drop box by
 * a few pixels and a lost connection meet. Each was seen failing on the code before the fix.
 *
 * Keys are sent to the element that has the keyboard (the driver's own keyboard), never to a chosen element — a chosen
 * one is focused first, which hides exactly the focus a person loses.
 */
class KeyboardAndRecoveryTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_a_playlist_line_moved_by_keyboard_keeps_the_keyboard_and_says_where_it_went(): void
    {
        [$owner, $alpha, $screen] = $this->screenWithLines(['Breakfast', 'Lunch', 'Dinner']);

        $this->browse(function (Browser $browser) use ($owner, $alpha, $screen) {
            $this->signIn($browser, $owner, $alpha, '/screens/'.$screen->id);
            $browser->waitFor('@playlist-line-2');

            $this->focus($browser, 'playlist-down-0');
            $this->press($browser, WebDriverKeys::ENTER);
            $browser->pause(300);
            $this->assertSame(['Lunch', 'Breakfast', 'Dinner'], $this->lineTitles($browser));
            $this->assertSame('playlist-down-1', $this->focused($browser), 'the keyboard did not stay on the moved line');
            $browser->waitForTextIn('@announcer', 'Breakfast moved to position 2 of 3.');

            // At the bottom its ↓ is disabled: the keyboard goes to its ↑.
            $this->press($browser, WebDriverKeys::ENTER);
            $browser->pause(300);
            $this->assertSame(['Lunch', 'Dinner', 'Breakfast'], $this->lineTitles($browser));
            $this->assertSame('playlist-up-2', $this->focused($browser));
            $browser->waitForTextIn('@announcer', 'Breakfast moved to position 3 of 3.');

            // × takes the line out and leaves the keyboard on the × of the line that took its place.
            $this->focus($browser, 'playlist-remove-0');
            $this->press($browser, WebDriverKeys::ENTER);
            $browser->pause(300);
            $this->assertSame(['Dinner', 'Breakfast'], $this->lineTitles($browser));
            $this->assertSame('playlist-remove-0', $this->focused($browser));
            $browser->waitForTextIn('@announcer', 'Lunch taken out of the playlist.');

            // The last line out: the keyboard goes to the Playlist heading, never to the top of the page.
            $this->press($browser, WebDriverKeys::ENTER);
            $browser->pause(200);
            $this->press($browser, WebDriverKeys::ENTER);
            $browser->pause(300);
            $this->assertSame([], $this->lineTitles($browser));
            $this->assertSame('playlist-heading', $this->focused($browser));
        });
    }

    public function test_a_channel_ad_moved_by_keyboard_keeps_the_keyboard(): void
    {
        $this->seedSuperAdmin();
        $alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->organizationMember($alpha, ['channel-view', 'channel-update']);
        $channel = Channel::factory()->create(['organization_id' => $alpha->id, 'name' => 'Our deals']);
        $first = ChannelAd::factory()->showing(['thumbnail_path' => null])->create(['channel_id' => $channel->id, 'title' => 'Monday deal', 'position' => 0]);
        ChannelAd::factory()->showing(['thumbnail_path' => null])->create(['channel_id' => $channel->id, 'title' => 'Tuesday deal', 'position' => 1]);
        ChannelAd::factory()->showing(['thumbnail_path' => null])->create(['channel_id' => $channel->id, 'title' => 'Friday deal', 'position' => 2]);

        $this->browse(function (Browser $browser) use ($owner, $alpha, $channel, $first) {
            $this->signIn($browser, $owner, $alpha, '/channels/'.$channel->id);
            $browser->waitFor('@channel-ad-row-'.$first->id);

            $this->focus($browser, 'channel-ad-down-'.$first->id);
            $this->press($browser, WebDriverKeys::ENTER);
            $browser->waitUsing(5, 100, fn () => $browser->script("return document.querySelector('[dusk=\"channel-ad-up-{$first->id}\"]').disabled === false;")[0]);
            $browser->pause(300);
            $this->assertSame('channel-ad-down-'.$first->id, $this->focused($browser), 'the keyboard did not stay on the moved ad');
            $browser->waitForTextIn('@announcer', 'Monday deal moved to position 2 of 3.');
        });
    }

    public function test_a_file_dropped_beside_the_upload_box_is_not_opened_in_place_of_the_page(): void
    {
        $this->seedSuperAdmin();
        $alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->organizationMember($alpha, ['media-view', 'media-store']);

        $this->browse(function (Browser $browser) use ($owner, $alpha) {
            $this->signIn($browser, $owner, $alpha, '/media');
            $browser->waitFor('@media-drop');

            // The browser opens a dropped file unless somebody takes the drop: off the box, nobody did.
            $taken = $browser->script(<<<'JS'
                const files = new DataTransfer();
                files.items.add(new File(['GIF89a'], 'menu.gif', { type: 'image/gif' }));
                const where = document.querySelector('h1');
                const over = new DragEvent('dragover', { dataTransfer: files, bubbles: true, cancelable: true });
                const drop = new DragEvent('drop', { dataTransfer: files, bubbles: true, cancelable: true });
                where.dispatchEvent(over);
                where.dispatchEvent(drop);
                return { over: over.defaultPrevented, drop: drop.defaultPrevented, effect: files.dropEffect };
            JS)[0];

            $this->assertTrue($taken['over'], 'a file held beside the box would be opened');
            $this->assertTrue($taken['drop'], 'a file dropped beside the box would be opened in place of the page');
            $this->assertSame('none', $taken['effect']);

            // A drag of something that is no file (the editor's layers) is left to whoever takes it.
            $left = $browser->script(<<<'JS'
                const words = new DataTransfer();
                words.setData('text/plain', 'a layer');
                const drop = new DragEvent('drop', { dataTransfer: words, bubbles: true, cancelable: true });
                document.querySelector('h1').dispatchEvent(drop);
                return drop.defaultPrevented;
            JS)[0];
            $this->assertFalse($left);
        });
    }

    public function test_escape_on_a_changed_form_asks_before_throwing_it_away(): void
    {
        $this->seedSuperAdmin();
        $alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->organizationMember($alpha, ['screen-view', 'screen-store', 'screen-update']);
        $screen = Screen::factory()->create(['organization_id' => $alpha->id, 'name' => 'Counter TV']);

        $this->browse(function (Browser $browser) use ($owner, $alpha, $screen) {
            $this->signIn($browser, $owner, $alpha, '/screens');
            $browser->waitFor('@edit-screen-'.$screen->id);

            // Nothing changed: Escape closes it at once.
            $browser->click('@edit-screen-'.$screen->id)->waitFor('@screen-edit-name')->pause(300);
            $this->press($browser, WebDriverKeys::ESCAPE);
            $browser->waitUntilMissing('@screen-edit-name');

            // A name changed: Escape asks, and the keyboard is on Keep Editing.
            $browser->click('@edit-screen-'.$screen->id)->waitFor('@screen-edit-name')->pause(300);
            $browser->click('@screen-edit-name');
            $this->press($browser, WebDriverKeys::END.' 2');
            $this->press($browser, WebDriverKeys::ESCAPE);
            $browser->waitFor('@screen-form-modal-discard-ask')->assertSee('Discard your changes?')->assertVisible('@screen-edit-name');
            $this->assertSame('screen-form-modal-keep-editing', $this->focused($browser));

            // Escape again keeps editing, with the typing where it was.
            $this->press($browser, WebDriverKeys::ESCAPE);
            $browser->waitUntilMissing('@screen-form-modal-discard-ask')->assertInputValue('@screen-edit-name', 'Counter TV 2');

            // Discard Changes closes it.
            $this->press($browser, WebDriverKeys::ESCAPE);
            $browser->waitFor('@screen-form-modal-discard')->click('@screen-form-modal-discard')->waitUntilMissing('@screen-edit-name');

            // Its own Cancel is the person's decision: no question.
            $browser->click('@edit-screen-'.$screen->id)->waitFor('@screen-edit-name')->pause(300);
            $browser->click('@screen-edit-name');
            $this->press($browser, WebDriverKeys::END.' 3');
            $browser->script("[...document.querySelectorAll('[dusk=\"screen-form\"] button')].find((b) => b.innerText.trim() === 'Cancel').click()");
            $browser->waitUntilMissing('@screen-edit-name')->assertMissing('@screen-form-modal-discard-ask');

            // Add Screen is a form of several fields: a click beside it no longer throws the code away.
            $browser->click('@add-screen')->waitFor('@screen-code')->pause(600);
            $browser->click('@screen-code');
            $this->press($browser, 'K7M4');
            $browser->script("document.querySelector('[dusk=\"screen-pair-form\"]').closest('[data-modal-root]').firstElementChild.click()");
            $browser->pause(300)->assertVisible('@screen-code')->assertInputValue('@screen-code', 'K7M4');
            $this->press($browser, WebDriverKeys::ESCAPE);
            $browser->waitFor('@screen-pair-modal-discard-ask');
        });
    }

    public function test_a_list_that_could_not_load_says_so_and_loads_again(): void
    {
        [$owner, $alpha, $screen] = $this->screenWithLines(['Breakfast', 'Lunch'], ['member-view']);

        $this->browse(function (Browser $browser) use ($owner, $alpha, $screen) {
            $this->signIn($browser, $owner, $alpha, '/dashboard');

            // The playlist: said where its lines would be, Save never offered, nothing added onto what nobody could see.
            $this->block($browser, ['*/screens/'.$screen->id.'/playlist']);
            $browser->visit('/screens/'.$screen->id)->waitFor('@playlist-load-failed')
                ->assertSeeIn('@playlist-load-failed', 'Could not load the playlist.')
                ->assertMissing('@playlist-empty')
                ->assertDisabled('@playlist-save');
            $this->block($browser, []);
            $browser->click('@playlist-load-failed-try-again')->waitFor('@playlist-line-1')->assertMissing('@playlist-load-failed');

            // The team: both lists say so, and the tabs count nothing.
            $this->block($browser, ['*/members/data']);
            $browser->visit('/members')->waitFor('@members-load-failed')
                ->assertMissing('@table-no-match')
                ->assertDontSee('Nobody matches');
            $this->assertSame('', trim($browser->script("return document.querySelector('[dusk=\"tab-members\"] .badge-neutral').offsetParent ? 'shown' : '';")[0]));
            $browser->click('@tab-invitations')->waitFor('@invitations-load-failed')->assertMissing('@invitations-empty');
            $this->block($browser, []);
            $browser->click('@invitations-load-failed-try-again')->waitFor('@invitations-empty')->click('@tab-members')
                ->waitForText($owner->email);
        });
    }

    public function test_closing_the_premium_gallery_puts_the_keyboard_back_on_create_ad(): void
    {
        $this->seedSuperAdmin();
        $alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
        $designer = $this->organizationMember($alpha, ['ad-view', 'ad-store']);
        $template = BuilderAd::factory()->published()->create(['organization_id' => null, 'name' => 'Burger menu']);

        $this->browse(function (Browser $browser) use ($designer, $alpha, $template) {
            $this->signIn($browser, $designer, $alpha, '/builder');
            $browser->waitFor('@new-ad');

            $this->openGallery($browser, $template);
            $this->press($browser, WebDriverKeys::ESCAPE);
            $browser->waitUntilMissing('@template-'.$template->id)->pause(300);
            $this->assertSame('new-ad', $this->focused($browser), 'Escape left the keyboard on a hidden button');

            // Back to how to start, then Cancel there: back on Create Ad too.
            $this->openGallery($browser, $template);
            $this->focus($browser, 'templates-back');
            $this->press($browser, WebDriverKeys::ENTER);
            $browser->waitFor('@new-ad-own')->pause(300);
            $this->press($browser, WebDriverKeys::ESCAPE);
            $browser->waitUntilMissing('@new-ad-own')->pause(300);
            $this->assertSame('new-ad', $this->focused($browser), 'Back then Escape left the keyboard on a hidden button');
        });
    }

    public function test_less_motion_leaves_nothing_sliding(): void
    {
        $this->seedSuperAdmin();
        $alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->organizationMember($alpha);

        $this->browse(function (Browser $browser) use ($owner, $alpha) {
            (new ChromeDevToolsDriver($browser->driver))->execute('Emulation.setEmulatedMedia', [
                'features' => [['name' => 'prefers-reduced-motion', 'value' => 'reduce']],
            ]);
            $this->signIn($browser, $owner, $alpha, '/dashboard');

            // Every transition and animation on the page — the stat cards' hover, the tabs, a toast as it comes — is
            // instant: none lasts longer than a millisecond.
            $browser->script("window.toast('Saved.', 'success')");
            $moving = $browser->waitFor('[role="status"] .pointer-events-auto')->script(<<<'JS'
                return [...document.querySelectorAll('*')].filter((el) => {
                    const style = getComputedStyle(el);
                    const longest = (list) => Math.max(...list.split(',').map((part) => parseFloat(part) * (part.trim().endsWith('ms') ? 0.001 : 1)));
                    return longest(style.transitionDuration) > 0.001 || (style.animationName !== 'none' && longest(style.animationDuration) > 0.001);
                }).map((el) => el.tagName.toLowerCase() + '.' + [...el.classList].slice(0, 3).join('.'));
            JS)[0];
            $this->assertSame([], $moving, 'these still move for somebody who asked for less motion');

            (new ChromeDevToolsDriver($browser->driver))->execute('Emulation.setEmulatedMedia', ['features' => []]);
        });
    }

    public function test_every_button_says_its_own_words_in_its_name(): void
    {
        $admin = $this->seedSuperAdmin();
        $alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->organizationMember($alpha);
        $staff = $this->organizationMember($alpha, Role::STAFF, 'staff@example.com');

        $this->browse(function (Browser $browser) use ($admin, $owner, $alpha, $staff) {
            // Members: Change Role on a colleague's row.
            $this->signIn($browser, $owner, $alpha, '/members');
            $browser->waitFor('@change-role-'.$staff->id);
            $this->assertSame([], $this->misnamed($browser), 'the Members page');

            // Users → Organizations: Remove on a membership; Remove Platform Role; Invite Owner.
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/users')->waitFor('@manage-organizations-'.$staff->id);
            $browser->click('@manage-organizations-'.$staff->id)->waitFor('@membership-remove-'.$alpha->id);
            $this->assertSame([], $this->misnamed($browser), 'the Organizations dialog of the Users page');
        });
    }

    /* ── Setting up ────────────────────────────────────────────────────── */

    /** @return array{0: User, 1: Organization, 2: Screen} a screen whose playlist plays these titles, in this order */
    private function screenWithLines(array $titles, array $morePermissions = []): array
    {
        $this->seedSuperAdmin();
        $alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->organizationMember($alpha, ['screen-view', 'screen-playlist', ...$morePermissions]);
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

    private function openGallery(Browser $browser, BuilderAd $template): void
    {
        $this->focus($browser, 'new-ad');
        $this->press($browser, WebDriverKeys::ENTER);
        $browser->waitFor('@new-ad-landscape')->pause(300);
        $this->focus($browser, 'new-ad-landscape');
        $this->press($browser, WebDriverKeys::ENTER);
        $browser->waitFor('@new-ad-premium')->pause(300);
        $this->focus($browser, 'new-ad-premium');
        $this->press($browser, WebDriverKeys::ENTER);
        $browser->waitFor('@template-'.$template->id)->pause(400);
    }

    /* ── The keyboard ──────────────────────────────────────────────────── */

    private function focus(Browser $browser, string $dusk): void
    {
        $browser->script("document.querySelector('[dusk=\"{$dusk}\"]').focus()");
    }

    /** Keys to whatever has the keyboard now. */
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
        return $browser->script(<<<'JS'
            return [...document.querySelectorAll('[dusk^="playlist-title-"]')].map((title) => title.innerText.trim());
        JS)[0];
    }

    /** Requests to these addresses fail as a lost connection does; none to let them through again. */
    private function block(Browser $browser, array $patterns): void
    {
        $tools = new ChromeDevToolsDriver($browser->driver);
        $tools->execute('Network.enable');
        $tools->execute('Network.setBlockedURLs', ['urls' => $patterns]);
    }

    /** Buttons and links named without the words they show (WCAG 2.5.3), as EveryPageRendersTest reads them. */
    private function misnamed(Browser $browser): array
    {
        return $browser->script(<<<'JS'
            const shown = (el) => el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden';
            return [...document.querySelectorAll('button[aria-label], a[aria-label], [role="button"][aria-label]')]
                .filter(shown)
                .map((el) => {
                    const words = [];
                    const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
                    while (walker.nextNode()) {
                        const holder = walker.currentNode.parentElement;
                        if (holder.closest('[aria-hidden="true"], .sr-only') || !shown(holder)) continue;
                        words.push(walker.currentNode.textContent);
                    }
                    return [el, words.join(' ').replace(/\s+/g, ' ').trim()];
                })
                .filter(([el, words]) => /[A-Za-z]{2,}/.test(words)
                    && !el.getAttribute('aria-label').replace(/\s+/g, ' ').toLowerCase().includes(words.toLowerCase()))
                .map(([el, words]) => `"${words}" is named "${el.getAttribute('aria-label')}"`);
        JS)[0];
    }
}
