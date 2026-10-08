<?php

namespace Tests\Browser;

use App\Models\ActivityLog;
use App\Models\BuilderAd;
use App\Models\Campaign;
use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Invitation;
use App\Models\Media;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\PlaylistItem;
use App\Models\Role;
use App\Models\Screen;
use App\Models\User;
use Facebook\WebDriver\Exception\NoSuchAlertException;
use Facebook\WebDriver\WebDriverKeys;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Every button on every page, pressed (owner, 2026-09-30: "ek ek button sub feature test karo"): each page is opened
 * as somebody allowed everything on it, every button and tab it shows is pressed in turn — the header's, the
 * sidebar's, the page's own and each row's — and after each press the console must be clean, no request may be
 * refused or fail, and the page must not break. A press that opens a dialog is followed by Escape; one that leads
 * away is followed by the page again.
 *
 * What is never pressed blindly — each has a flow test of its own that presses it for real: a plain form's submit
 * (it posts for real: sign out, the organization switcher's organizations, Settings' forms) and signing in as somebody else. A
 * button that asks before it acts (every delete, the yearly maintenance) is pressed: only its question opens.
 */
class EveryButtonWorksTest extends DuskTestCase
{
    use DatabaseMigrations;

    /** Pressed by a flow test of its own, never blindly here. */
    private const NEVER = '^(log out|sign out|stop viewing|log in as)';

    /** @var list<string> the pictures this test put on the disk for its made-up rows */
    private array $picturesPut = [];

    /**
     * The pictures this test put there go with it — each by its own path, and before the sweep in DuskTestCase, which
     * would take a poster that Publish replaced for a stray file (no row names it any more).
     */
    protected function tearDown(): void
    {
        foreach ($this->picturesPut as $path) {
            Storage::disk('public')->delete($path);
        }

        $this->picturesPut = [];

        parent::tearDown();
    }

    public function test_every_button_above_the_organizations_works(): void
    {
        $admin = $this->seedSuperAdmin();
        [$alpha, $screen, $platformChannel] = $this->aOrganization();
        Organization::factory()->create(['name' => 'Orphan Organization']);
        Campaign::factory()->create(['name' => 'Summer Cola Campaign']);
        Invitation::factory()->forPlatform()->create(['email' => 'new.colleague@example.com', 'role_id' => Role::superAdminId()]);
        $this->giveEveryRowItsPictures();

        $this->browse(function (Browser $browser) use ($admin, $screen, $platformChannel) {
            $this->freshSession($browser);
            $browser->loginAs($admin);

            $this->pressEveryButton($browser, [
                '/dashboard',
                '/organizations',
                '/users',
                '/roles',
                '/permissions',
                '/activity',
                '/channels',
                '/channels/'.$platformChannel->id,
                '/campaigns',
                '/media',
                '/builder',
                '/builder/assets',
                '/screens',
                '/screens/'.$screen->id,
                '/profile',
            ]);
        });
    }

    public function test_every_button_inside_an_organization_works(): void
    {
        $this->seedSuperAdmin();
        [$alpha, $screen] = $this->aOrganization();
        $ownChannel = Channel::factory()->create(['organization_id' => $alpha->id, 'name' => 'Alpha Weekend Deals']);
        $ad = BuilderAd::where('organization_id', $alpha->id)->firstOrFail();

        // Everything an organization's role may hold, so no button is missing for want of a permission.
        $member = $this->organizationMember($alpha, [
            ...Permission::ORGANIZATION, 'organization-view', 'organization-store', 'organization-destroy',
            'channel-view', 'channel-store', 'channel-update', 'channel-destroy', 'activity-view',
        ], 'manager@example.com', 'Everything');
        $this->giveEveryRowItsPictures();

        $this->browse(function (Browser $browser) use ($member, $alpha, $screen, $ownChannel, $ad) {
            $this->freshSession($browser);
            $browser->loginAs($member);
            $this->switchToOrganization($browser, $alpha);

            $this->pressEveryButton($browser, [
                '/dashboard',
                '/screens',
                '/screens/'.$screen->id,
                '/media',
                '/channels',
                '/channels/'.$ownChannel->id,
                '/builder',
                '/builder/assets',
                '/builder/'.$ad->id,
                '/members',
                '/roles',
                '/activity',
                '/settings/organization',
                '/settings/billing',
                '/profile',
            ]);
        });
    }

    /* ── Helpers ─────────────────────────────────────────────────────── */

    /**
     * An organization with something on every page: people and an invitation, two screens (one with a playlist whose
     * first line keeps hours), files, a published ad, the platform's channel with an ad, and a log.
     *
     * @return array{0: Organization, 1: Screen, 2: Channel}
     */
    private function aOrganization(): array
    {
        $alpha = Organization::factory()->create(['name' => 'Alpha Mart']);

        $owner = User::factory()->create(['first_name' => 'Ali', 'last_name' => 'Raza', 'email' => 'ali@example.com']);
        $owner->organizations()->attach($alpha->id, ['role_id' => Role::owner()->id]);

        $cashier = Role::create(['name' => 'Weekend Cashier', 'organization_id' => $alpha->id]);
        $cashier->permissions()->sync(Permission::whereIn('name', ['screen-view', 'media-view'])->pluck('id'));
        User::factory()->create(['first_name' => 'Bilal', 'last_name' => 'Ahmed', 'email' => 'bilal@example.com'])
            ->organizations()->attach($alpha->id, ['role_id' => $cashier->id]);

        Invitation::factory()->create(['organization_id' => $alpha->id, 'email' => 'pending@example.com', 'role_id' => $cashier->id, 'invited_by' => $owner->id]);

        $menu = Media::factory()->create(['organization_id' => $alpha->id, 'title' => 'Breakfast Menu Board']);
        Media::factory()->create(['organization_id' => $alpha->id, 'title' => 'Lunch Specials']);

        $screen = Screen::factory()->create(['organization_id' => $alpha->id, 'name' => 'Front Counter TV']);
        Screen::factory()->create(['organization_id' => $alpha->id, 'name' => 'Drive-through Screen', 'last_seen_at' => null]);
        PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $menu->id, 'position' => 0, 'duration_seconds' => 10])
            ->scheduleRules()->create(['start_time' => '06:00', 'end_time' => '11:30', 'position' => 0]);

        BuilderAd::factory()->withText('Two for one')->published()->create(['organization_id' => $alpha->id, 'name' => 'Weekend Burgers']);

        $platformChannel = Channel::factory()->create(['name' => 'GAMA Wholesale']);
        $promo = Media::factory()->create(['organization_id' => null, 'title' => 'Mango Summer Promotion']);
        ChannelAd::factory()->create(['channel_id' => $platformChannel->id, 'media_id' => $promo->id]);

        ActivityLog::record('media.uploaded', $menu, 'Uploaded Breakfast Menu Board to the library', $owner);

        return [$alpha, $screen, $platformChannel];
    }

    /**
     * The files the made-up rows name — every thumbnail (an Ad Builder page's poster too), and a picture's own file —
     * so each page loads its pictures as a real organization's does, and a picture that does not load is a problem again.
     * tearDown takes them away.
     */
    private function giveEveryRowItsPictures(): void
    {
        foreach (Media::withoutGlobalScopes()->get() as $media) {
            foreach (array_filter([$media->thumbnail_path, $media->type === Media::TYPE_IMAGE ? $media->path : null]) as $path) {
                $this->picturesPut[] = $this->putImage($path, 40, 120, 200);
            }
        }

        foreach (Campaign::all() as $campaign) {
            foreach (array_filter([$campaign->thumbnail_path, $campaign->path]) as $path) {
                $this->picturesPut[] = $this->putImage($path, 200, 80, 40);
            }
        }
    }

    /**
     * Press every button of every page and fail once, with everything that went wrong on every page.
     *
     * @param  list<string>  $pages
     */
    private function pressEveryButton(Browser $browser, array $pages): void
    {
        $problems = [];
        $pressed = 0;

        foreach ($pages as $page) {
            $count = $this->open($browser, $page);
            $problems = [...$problems, ...$this->whatWentWrong($browser, $page, '(opening the page)')];

            for ($index = 0; $index < $count; $index++) {
                if (parse_url($browser->driver->getCurrentURL(), PHP_URL_PATH) !== parse_url($page, PHP_URL_PATH)) {
                    $this->open($browser, $page);
                }

                $label = $browser->script(<<<JS
                    const el = document.querySelector('[data-press="{$index}"]');
                    if (!el || el.disabled || el.getClientRects().length === 0) return null;
                    el.scrollIntoView({ block: 'center' });
                    el.click();
                    return el.getAttribute('data-press-label');
                JS)[0];

                if ($label === null) {
                    continue;
                }

                $pressed++;
                $browser->pause(700);
                $this->dismissNativeDialog($browser);
                $problems = [...$problems, ...$this->whatWentWrong($browser, $page, $label)];

                // Close whatever the press opened (a dialog, a menu) before the next one.
                $browser->driver->getKeyboard()->sendKeys(WebDriverKeys::ESCAPE);
                $browser->pause(150);
            }
        }

        $this->assertSame([], $problems, "Pressing every button went wrong:\n".implode("\n", $problems));
        $this->assertGreaterThan(count($pages) * 2, $pressed, 'hardly any button was pressed — did the pages load?');
    }

    /** Open a page, wait for it to settle, listen to its requests and number its buttons. How many there are. */
    private function open(Browser $browser, string $page): int
    {
        $browser->visit($page);
        $this->waitForAlpine($browser);
        $browser->waitUntilMissingText('Loading...', 10)->pause(300);

        $never = self::NEVER;

        return $browser->script(<<<JS
            // Every answer the page gets from now on: a refused or failed one is a problem.
            if (!window.__pressHooked) {
                window.__pressHooked = true;
                window.__pressBad = [];
                const open = XMLHttpRequest.prototype.open;
                XMLHttpRequest.prototype.open = function (method, url, ...rest) {
                    this.addEventListener('loadend', () => {
                        if (this.status >= 400) window.__pressBad.push(method + ' ' + url + ' answered ' + this.status);
                    });
                    return open.call(this, method, url, ...rest);
                };
                const fetch = window.fetch;
                window.fetch = function (input, init = {}) {
                    return fetch.call(this, input, init).then((response) => {
                        if (response.status >= 400) window.__pressBad.push((init.method || 'GET') + ' ' + (input.url || input) + ' answered ' + response.status);
                        return response;
                    });
                };
            }

            const never = new RegExp('{$never}', 'i');
            const plainSubmit = (el) => el.type === 'submit' && el.form
                && !el.form.getAttributeNames().some((name) => name.startsWith('@submit') || name.startsWith('x-on:submit'));

            const buttons = [...document.querySelectorAll('button, [role="tab"]')].filter((el) => {
                if (el.disabled || el.getClientRects().length === 0) return false;
                if (getComputedStyle(el).visibility === 'hidden') return false;
                const label = (el.getAttribute('aria-label') || el.textContent || el.title || '').trim().replace(/\\s+/g, ' ');
                el.setAttribute('data-press-label', label.slice(0, 60) || el.getAttribute('dusk') || '(no label)');
                return !never.test(label) && !plainSubmit(el);
            });

            buttons.forEach((el, index) => el.setAttribute('data-press', index));

            return buttons.length;
        JS)[0];
    }

    /**
     * What went wrong since the last look: an error in the console, a request refused or failed, or a page that
     * broke.
     *
     * @return list<string>
     */
    private function whatWentWrong(Browser $browser, string $page, string $label): array
    {
        // The browser's own noise; a file chooser, which a script's click may not open (a person's click does); and
        // the Ad Builder asking before its unsaved changes are left behind, which Chrome holds back when no person
        // has touched the page — the press after it is what left, not a fault.
        $noise = [
            'favicon', 'DevTools', 'chrome-extension',
            'File chooser dialog can only be shown with a user activation',
            "Blocked attempt to show a 'beforeunload' confirmation panel",
        ];

        $console = collect($browser->driver->manage()->getLog('browser'))
            ->filter(fn (array $entry) => ($entry['level'] ?? '') === 'SEVERE')
            ->pluck('message')
            ->reject(fn (string $message) => Str::contains($message, $noise))
            ->map(fn (string $message) => 'console: '.Str::limit($message, 220));

        $requests = collect($browser->script('const bad = window.__pressBad || []; window.__pressBad = []; return bad;')[0])
            ->map(fn (string $line) => 'request: '.$line);

        $text = (string) $browser->script('return document.body ? document.body.innerText : "";')[0];
        $broken = collect(['Server Error', 'Whoops', 'is not defined', 'Undefined variable', 'Page Expired'])
            ->filter(fn (string $words) => str_contains($text, $words))
            ->map(fn (string $words) => "page says \"{$words}\"");

        return $console->concat($requests)->concat($broken)
            ->map(fn (string $what) => "{$page} → [{$label}] {$what}")
            ->values()->all();
    }

    /** A native alert or confirm would stop the next command: say no to it (the panel's own dialogs are not native). */
    private function dismissNativeDialog(Browser $browser): void
    {
        try {
            $browser->driver->switchTo()->alert()->dismiss();
        } catch (NoSuchAlertException) {
            // none
        }
    }
}
