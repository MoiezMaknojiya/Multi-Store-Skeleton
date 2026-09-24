<?php

namespace Tests\Browser;

use App\Models\ActivityLog;
use App\Models\BuilderAd;
use App\Models\Campaign;
use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Daypart;
use App\Models\Invitation;
use App\Models\Media;
use App\Models\Permission;
use App\Models\PlaylistItem;
use App\Models\Role;
use App\Models\Screen;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Every page and every dialog at a phone's width (375 px), checked for what a phone does to a layout that was
 * only ever looked at on a desk (owner, 2026-09-24: "mobile mein ... design kharab nahi ho"):
 *
 *   - the page never scrolls sideways: a wide table scrolls inside its own card, and nothing else may;
 *   - no pill, button, tab, heading, label or short cell breaks its words over two lines — "Owner · Alpha Mart"
 *     as three lines of a pill was the owner's report;
 *   - no button, pill or field is cut off by the card or the dialog it sits in.
 *
 * And every page again at a laptop's width (1280 px, the sidebar open), where the layouts turn into columns side
 * by side and a name may wrap again: still no pill, button, tab or heading breaks, nothing is cut off, and the
 * page never scrolls sideways (a table too wide for its card, with names as long as these, scrolls inside it).
 *
 * Each page is opened in a frame 375 px wide, so the page's own breakpoints are a phone's whatever the size of
 * the test's window, and every dialog is opened the way its page opens it (the `open-modal` event). The names
 * are as long as a real shop's, so a layout that only fits "Test" is found out.
 */
class EveryPageFitsAPhoneTest extends DuskTestCase
{
    use DatabaseMigrations;

    private const PHONE_WIDTH = 375;

    private const LAPTOP_WIDTH = 1280;

    public function test_every_page_and_dialog_above_the_stores_fits_a_phone(): void
    {
        $admin = $this->seedSuperAdmin();
        [$alpha, $screen, $platformChannel] = $this->aBusyShop();

        Invitation::factory()->forPlatform()->create([
            'email' => 'new.platform.colleague@example.com',
            'role_id' => Role::superAdminId(),
        ]);
        Campaign::factory()->create(['name' => 'Summer Cola Refresh Campaign', 'advertiser_name' => 'Coca-Cola Bottling Company of Texas']);

        $this->browse(function (Browser $browser) use ($admin, $screen, $platformChannel) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/dashboard');

            $this->assertEveryPageFits($browser, [
                '/dashboard' => [],
                '/users' => ['invite-platform-member', 'manage-stores', 'confirm-account-deletion', 'confirm-remove-platform-role', 'confirm-revoke-platform-invitation'],
                '/stores' => ['store-form-modal', 'invite-store-owner', 'confirm-store-deletion', 'confirm-store-ads'],
                '/roles' => ['role-form', 'role-permissions', 'confirm-role-deletion'],
                '/permissions' => ['permission-form-modal', 'confirm-permission-deletion'],
                '/activity' => ['confirm-activity-maintenance'],
                '/channels' => ['channel-form-modal', 'confirm-channel-deletion'],
                '/channels/'.$platformChannel->id => ['channel-ad-modal'],
                '/campaigns' => ['campaign-form-modal'],
                '/builder' => ['new-ad-orientation', 'confirm-ad-deletion'],
                '/builder/assets' => ['confirm-asset-deletion'],
                '/builder/create' => [],
                '/media' => ['media-upload-modal', 'media-form-modal'],
                '/screens' => ['screen-pair-modal', 'screen-form-modal'],
                '/screens/'.$screen->id => ['playlist-copy-modal', 'playlist-schedule-modal'],
                '/dayparts' => ['daypart-form-modal'],
                '/profile' => ['confirm-user-deletion'],
            ]);
        });
    }

    public function test_every_page_and_dialog_inside_a_store_fits_a_phone(): void
    {
        $this->seedSuperAdmin();
        [$alpha, $screen] = $this->aBusyShop();

        // Everything a store's role may hold, so no page of the store is skipped for want of a permission.
        $member = $this->storeMember($alpha, [
            ...Permission::STORE, 'store-view', 'store-store', 'store-destroy',
            'channel-view', 'channel-store', 'channel-update', 'channel-destroy', 'activity-view',
        ], 'rukhsana.siddiqui.manager@example.com', 'Store Manager with every permission');
        $ownChannel = Channel::factory()->create(['store_id' => $alpha->id, 'name' => 'Alpha Weekend Deals and Offers']);

        $this->browse(function (Browser $browser) use ($member, $alpha, $screen, $ownChannel) {
            $this->freshSession($browser);
            $browser->loginAs($member);
            $this->switchToStore($browser, $alpha);

            $this->assertEveryPageFits($browser, [
                '/dashboard' => [],
                '/screens' => ['screen-pair-modal', 'screen-form-modal'],
                '/screens/'.$screen->id => ['playlist-copy-modal', 'playlist-schedule-modal'],
                '/media' => ['media-upload-modal', 'media-form-modal'],
                '/dayparts' => ['daypart-form-modal'],
                '/channels' => ['channel-form-modal', 'confirm-channel-deletion'],
                '/channels/'.$ownChannel->id => ['channel-ad-modal'],
                '/builder' => ['new-ad-orientation', 'confirm-ad-deletion'],
                '/builder/assets' => ['confirm-asset-deletion'],
                '/builder/create' => [],
                '/members' => ['invite-member', 'change-member-role', 'remove-member', 'leave-store', 'revoke-invitation'],
                '/roles' => ['role-form', 'confirm-role-deletion'],
                '/activity' => [],
                '/settings/store' => ['open-store', 'confirm-store-deletion'],
                '/profile' => ['confirm-user-deletion'],
            ]);
        });
    }

    public function test_the_store_picker_and_the_dashboard_of_somebody_in_no_store_fit_a_phone(): void
    {
        $this->seedSuperAdmin();
        $this->aBusyShop();

        // Muhammad Ali works in two shops, so he is asked which one first; a person just signed up is in none.
        $inTwoShops = User::where('email', 'muhammad.ali.raza.khan@example.com')->firstOrFail();
        $inNoShop = User::factory()->create(['first_name' => 'Newly Registered', 'last_name' => 'Team Member']);

        $this->browse(function (Browser $browser) use ($inTwoShops, $inNoShop) {
            $this->freshSession($browser);
            $browser->loginAs($inTwoShops)->visit('/select-store')->assertPathIs('/select-store');
            $this->assertEveryPageFits($browser, ['/select-store' => []]);

            $this->freshSession($browser);
            $browser->loginAs($inNoShop)->visit('/dashboard')->assertPathIs('/dashboard');
            $this->assertEveryPageFits($browser, ['/dashboard' => []]);
        });
    }

    public function test_the_doors_a_guest_comes_in_by_fit_a_phone(): void
    {
        $alpha = Store::factory()->create(['name' => 'Alpha Mart Downtown Superstore']);
        $token = str_repeat('k', 64);
        Invitation::factory()->withToken($token)->create([
            'store_id' => $alpha->id,
            'email' => 'invited.new.cashier@example.com',
            'role_id' => Role::starter(Role::STAFF)->id,
        ]);

        $this->browse(function (Browser $browser) use ($token) {
            $this->freshSession($browser);
            $browser->visit('/login');

            $this->assertEveryPageFits($browser, [
                '/login' => [],
                '/register' => [],
                '/forgot-password' => [],
                '/reset-password/'.str_repeat('r', 64).'?email=someone%40example.com' => [],
                '/invitations/'.$token => [],
            ]);
        });
    }

    public function test_on_a_phone_the_ad_builder_says_it_needs_a_wider_screen_and_on_a_desk_it_is_there(): void
    {
        $this->seedSuperAdmin();
        $alpha = Store::factory()->create(['name' => 'Alpha Mart']);
        $designer = $this->storeMember($alpha, ['ad-view', 'ad-store', 'ad-update'], 'designer@example.com', 'Designer');

        $this->browse(function (Browser $browser) use ($designer, $alpha) {
            $this->freshSession($browser);
            $browser->loginAs($designer);
            $this->switchToStore($browser, $alpha);
            $browser->visit('/builder');

            $atPhone = $this->measure($browser, '/builder/create?orientation=landscape', [], self::PHONE_WIDTH, 'editor');
            $atDesk = $this->measure($browser, '/builder/create?orientation=landscape', [], 1280, 'editor');

            // A phone: the notice, a way back to the ads, and nothing of the editor — and so nothing sideways.
            $this->assertSame(['notice' => true, 'stage' => false, 'back' => '/builder', 'sideways' => 0], $atPhone['/builder/create?orientation=landscape']);
            // A desk: the editor, and no notice.
            $this->assertSame(['notice' => false, 'stage' => true, 'back' => '/builder', 'sideways' => 0], $atDesk['/builder/create?orientation=landscape']);
        });
    }

    /* ── Helpers ─────────────────────────────────────────────────────── */

    /**
     * A shop the way a real one looks: a long name, people with roles in two shops, a pending invitation, a
     * screen with a playlist, files with long names and descriptions, a daypart, a published ad, the platform's
     * channel with an ad, and an activity log with something in it.
     *
     * @return array{0: Store, 1: Screen, 2: Channel}
     */
    private function aBusyShop(): array
    {
        $alpha = Store::factory()->create(['name' => 'Alpha Mart Downtown Superstore', 'city' => 'San Antonio', 'state' => 'TX', 'zip_code' => '78205']);
        $beta = Store::factory()->create(['name' => 'Beta Store Northside', 'city' => 'Fort Worth', 'state' => 'TX', 'zip_code' => '76102']);

        $owner = User::factory()->create(['first_name' => 'Muhammad Ali', 'last_name' => 'Raza Khan', 'email' => 'muhammad.ali.raza.khan@example.com']);
        $owner->stores()->attach($alpha->id, ['role_id' => Role::owner()->id]);
        $manager = Role::create(['name' => 'Assistant Store Manager', 'store_id' => $beta->id]);
        $owner->stores()->attach($beta->id, ['role_id' => $manager->id]);

        $cashier = Role::create(['name' => 'Weekend Cashier', 'store_id' => $alpha->id]);
        $cashier->permissions()->sync(Permission::whereIn('name', ['screen-view', 'media-view'])->pluck('id'));

        foreach ([['Bilal Ahmed', 'Siddiqui'], ['Fatima Zahra', 'Hussain'], ['Christopher', 'Montgomery-Wellington']] as $index => [$first, $last]) {
            User::factory()->create(['first_name' => $first, 'last_name' => $last, 'email' => 'cashier'.$index.'.alpha.mart@example.com'])
                ->stores()->attach($alpha->id, ['role_id' => $cashier->id]);
        }

        Invitation::factory()->create([
            'store_id' => $alpha->id,
            'email' => 'pending.new.team.member@example.com',
            'role_id' => $cashier->id,
            'invited_by' => $owner->id,
        ]);

        $menu = Media::factory()->create([
            'store_id' => $alpha->id,
            'title' => 'Breakfast, Lunch and Dinner Specials Menu Board',
            'description' => 'The one on the big screen by the counter — swap it every season, and again for Ramadan.',
        ]);
        $video = Media::factory()->create([
            'store_id' => $alpha->id,
            'title' => '2_MinuteMaid-wobbler-Gama Adds September 2026',
            'type' => Media::TYPE_VIDEO,
            'mime_type' => 'video/mp4',
            'duration_seconds' => 30,
            'expires_at' => now()->addMonth(),
        ]);

        $screen = Screen::factory()->create(['store_id' => $alpha->id, 'name' => 'Front Counter Menu Board Television']);
        Screen::factory()->create(['store_id' => $alpha->id, 'name' => 'Drive-through Window Screen']);
        PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $menu->id, 'position' => 0, 'duration_seconds' => 10]);
        PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $video->id, 'position' => 1]);

        Daypart::factory()->between('06:00', '11:30')->create(['store_id' => $alpha->id, 'name' => 'Breakfast Rush Hours']);
        Daypart::factory()->overnight()->create(['store_id' => $alpha->id, 'name' => 'Late Night Takeaway Window']);

        BuilderAd::factory()->withText('Two for one')->published()->create(['store_id' => $alpha->id, 'name' => 'Weekend Special Two for One Burgers']);

        $platformChannel = Channel::factory()->create(['name' => 'GAMA Wholesale Promotions Channel']);
        $promo = Media::factory()->create(['store_id' => null, 'title' => 'Minute Maid Mango Summer Promotion']);
        ChannelAd::factory()->create(['channel_id' => $platformChannel->id, 'media_id' => $promo->id]);

        ActivityLog::record('media.uploaded', $menu, 'Uploaded Breakfast, Lunch and Dinner Specials Menu Board to the library', $owner);
        ActivityLog::record('member.invited', $alpha, 'Invited pending.new.team.member@example.com as Weekend Cashier', $owner);
        ActivityLog::record('screen.paired', $screen, 'Paired screen Front Counter Menu Board Television', $owner);

        return [$alpha, $screen, $platformChannel];
    }

    /**
     * Open each page — and each of its dialogs — in a frame of a phone's width, then each page again at a laptop's,
     * measure it, and fail with every problem on every page at once, so one run says everything that is wrong.
     *
     * @param  array<string, list<string>>  $pages  a page's address => the names of the dialogs to open on it
     */
    private function assertEveryPageFits(Browser $browser, array $pages): void
    {
        $problems = [];

        foreach ($pages as $page => $dialogs) {
            foreach ($this->measure($browser, $page, $dialogs, self::PHONE_WIDTH) as $where => $found) {
                if ($found !== 'fits') {
                    $problems['phone: '.$where] = $found;
                }
            }

            // The dialogs are a phone's problem: on a laptop each one is a column far narrower than the page.
            foreach ($this->measure($browser, $page, [], self::LAPTOP_WIDTH, 'laptop') as $where => $found) {
                if ($found !== 'fits') {
                    $problems['laptop: '.$where] = $found;
                }
            }
        }

        $this->assertSame([], $problems, "Where a page does not fit:\n".json_encode($problems, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * What a frame of $width makes of a page and its dialogs: 'fits', or what is wrong. With $mode 'laptop', a name
     * or a cell may wrap at its spaces (a pill, a button, a tab or a heading still may not). With $mode 'editor',
     * what the Ad Builder shows instead (the notice or the stage).
     *
     * @return array<string, mixed>
     */
    private function measure(Browser $browser, string $page, array $dialogs, int $width, string $mode = 'layout'): array
    {
        $browser->driver->manage()->timeouts()->setScriptTimeout(90);

        $json = $browser->driver->executeAsyncScript(self::MEASURE.<<<'JS'
            const [page, dialogs, width, mode, done] = arguments;
            measure(page, dialogs, width, mode).then(
                (result) => done(JSON.stringify(result)),
                (error) => done(JSON.stringify({ [page]: 'the page could not be measured: ' + error })),
            );
        JS, [$page, $dialogs, $width, $mode]);

        return json_decode((string) $json, true);
    }

    /** The measuring itself, in the page: a frame of the width asked for, and what is wrong in it. */
    private const MEASURE = <<<'JS'
        const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

        function problems(win, laptop) {
            const doc = win.document;
            const vw = doc.documentElement.clientWidth;
            const found = { sideways: doc.documentElement.scrollWidth - vw, wrapped: [], clipped: [], outside: [] };
            const shown = (el) => el.offsetParent !== null && win.getComputedStyle(el).visibility !== 'hidden';
            const name = (el) => (String(el.className || '').match(/(badge|btn)-[a-z-]+/) || [el.tagName.toLowerCase()])[0];
            const words = (el) => ((el.innerText || el.value || el.getAttribute('aria-label') || '') + '').trim().replace(/\s+/g, ' ');

            // How many lines the words of an element take: the text boxes, grouped by where each line starts.
            const lines = (el) => {
                const tops = [];
                const walker = doc.createTreeWalker(el, win.NodeFilter.SHOW_TEXT);
                for (let node = walker.nextNode(); node; node = walker.nextNode()) {
                    if (!node.textContent.trim()) continue;
                    const range = doc.createRange();
                    range.selectNodeContents(node);
                    for (const r of range.getClientRects()) {
                        if (r.width > 0 && r.height > 0 && !tops.some((t) => Math.abs(t - r.top) < 6)) tops.push(r.top);
                    }
                }
                return tops.length;
            };
            // Two lines on purpose: a <br>, or blocks stacked inside.
            const onPurpose = (el) => el.querySelector('br, div, p, table, ul, img') !== null
                || [...el.children].some((child) => ['block', 'flex', 'grid'].includes(win.getComputedStyle(child).display));

            // On a laptop a name or a cell may wrap at its spaces — that is how most tables fit their card — but a
            // pill, a button, a tab or a heading never does.
            const oneLine = laptop
                ? '[class*="badge-"], [class*="btn-"], button, th, [role="tab"]'
                : '[class*="badge-"], [class*="btn-"], button, a, th, td, label, [role="tab"]';
            doc.querySelectorAll(oneLine).forEach((el) => {
                if (!shown(el) || el.closest('.cell-prose')) return;
                const text = words(el);
                if (!text || text.length > 40 || onPurpose(el)) return;
                if (lines(el) > 1) found.wrapped.push(name(el) + ': ' + text);
            });

            // What part of an element is on the glass: its box cut by every clipping box above it. A scroller above
            // it ends the search — what it clips can be scrolled to.
            doc.querySelectorAll('[class*="badge-"], [class*="btn-"], button, a, input, select, textarea').forEach((el) => {
                if (!shown(el)) return;
                const r = el.getBoundingClientRect();
                if (r.width === 0) return;
                let left = r.left;
                let right = r.right;
                for (let p = el.parentElement; p && p !== doc.documentElement; p = p.parentElement) {
                    const ox = win.getComputedStyle(p).overflowX;
                    if (ox === 'auto' || ox === 'scroll') break;
                    if (ox === 'hidden' || ox === 'clip') {
                        const box = p.getBoundingClientRect();
                        left = Math.max(left, box.left);
                        right = Math.min(right, box.right);
                    }
                }
                const visible = right - left;
                if (visible > 0 && visible < r.width - 1) found.clipped.push(name(el) + ': ' + (words(el).slice(0, 30) || '(icon)') + ' cut by ' + Math.round(r.width - visible) + 'px');
            });

            // Anything past the right edge with nothing above it to scroll or clip it: the page itself is too wide.
            doc.querySelectorAll('body *').forEach((el) => {
                if (!shown(el)) return;
                const r = el.getBoundingClientRect();
                if (r.width === 0 || r.right <= vw + 1 || r.left >= vw) return;
                for (let p = el.parentElement; p; p = p.parentElement) {
                    const ox = win.getComputedStyle(p).overflowX;
                    if (ox === 'auto' || ox === 'scroll' || ox === 'hidden' || ox === 'clip') return;
                }
                found.outside.push(el.tagName.toLowerCase() + '.' + String(el.className).split(' ').slice(0, 3).join('.'));
            });

            found.wrapped = [...new Set(found.wrapped)];
            found.clipped = [...new Set(found.clipped)];
            found.outside = [...new Set(found.outside)].slice(0, 6);

            return found.sideways > 0 || found.wrapped.length || found.clipped.length || found.outside.length ? found : 'fits';
        }

        async function measure(page, dialogs, width, mode) {
            // A laptop's sidebar is open unless somebody closed it there: measure beside it, the narrower card.
            localStorage.removeItem('sidebarOpen');
            const frame = document.createElement('iframe');
            frame.style.cssText = `position:fixed;left:0;top:0;width:${width}px;height:812px;border:0;z-index:2147483647;background:#fff`;
            document.body.appendChild(frame);
            await new Promise((resolve) => { frame.onload = resolve; frame.src = page; setTimeout(resolve, 15000); });
            const win = frame.contentWindow;

            // The page's own listings arrive after it loads: wait for "Loading..." to go, and Alpine to settle.
            for (let i = 0; i < 40 && /Loading\.\.\./.test(win.document.body.innerText); i++) await wait(250);
            await wait(600);

            const results = {};

            if (mode === 'editor') {
                const notice = win.document.querySelector('[dusk="builder-needs-wider-screen"]');
                const stage = win.document.querySelector('[dusk="ad-stage"]');
                const back = notice ? notice.querySelector('a') : null;
                results[page] = {
                    notice: !!notice && notice.offsetParent !== null,
                    stage: !!stage && stage.offsetParent !== null,
                    back: back ? new URL(back.href).pathname : null,
                    sideways: win.document.documentElement.scrollWidth - win.document.documentElement.clientWidth,
                };
                frame.remove();
                return results;
            }

            results[page] = problems(win, mode === 'laptop');

            for (const dialog of dialogs) {
                win.dispatchEvent(new win.CustomEvent('open-modal', { detail: dialog }));
                await wait(450);
                results[page + ' → dialog ' + dialog] = problems(win, mode === 'laptop');
                win.dispatchEvent(new win.CustomEvent('close-modal', { detail: dialog }));
                await wait(300);
            }

            frame.remove();
            return results;
        }
    JS;
}
