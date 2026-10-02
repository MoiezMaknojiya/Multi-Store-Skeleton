<?php

namespace Tests\Browser;

use App\Models\Channel;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Screen;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Every page of the panel, opened by the people allowed to open it, checking the two things no
 * backend test can see:
 *
 *   1. the page's Alpine component really initialised and its listing really answered — a table
 *      stuck on "Loading..." is exactly what a broken `x-data` looks like, and the page still
 *      returns 200 while it happens (see the Blade and Alpine gotchas in
 *      .claude/rules/02-project-conventions.md, both of which shipped as a 200 with a dead table);
 *   2. the browser console stayed clean;
 *   3. a screen reader can use it: a title, one h1, and a name for every control and picture on it.
 */
class EveryPageRendersTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_every_page_above_the_organizations_renders_with_a_clean_console(): void
    {
        $admin = $this->seedSuperAdmin();
        Organization::factory()->create(['name' => 'Alpha Mart']);
        // One of the platform's own channels, so the page of the ads it carries has one to open.
        $channel = Channel::factory()->create(['name' => 'GAMA Wholesale']);

        $this->browse(function (Browser $browser) use ($admin, $channel) {
            $this->freshSession($browser);
            $browser->loginAs($admin);

            $this->walk($browser, [
                '/dashboard',
                '/users',
                '/organizations',
                '/permissions',
                '/roles',
                '/activity',
                '/channels',
                '/channels/'.$channel->id,   // one channel's ads
                '/campaigns',
                '/builder',                  // the Ad Builder's two pages: the ads, whose New ad asks the shape…
                '/builder/assets',           // …and the shelf…
                '/builder/create?orientation=portrait',   // …then the editor, on an empty stage of that shape
                '/profile',
            ]);
        });
    }

    public function test_every_page_inside_an_organization_renders_with_a_clean_console(): void
    {
        $this->seedSuperAdmin();
        $organization = Organization::factory()->create(['name' => 'Alpha Mart']);

        // A custom role of this organization holding everything an organization's role may hold, so no page is
        // skipped for want of a permission — the Owner role itself carries no channel permissions.
        $owner = $this->organizationMember($organization, [
            ...Permission::ORGANIZATION, 'organization-view', 'organization-store', 'organization-destroy',
            'channel-view', 'channel-store', 'channel-update', 'channel-destroy', 'activity-view',
        ], 'owner@example.com', 'Everything');

        $screen = Screen::factory()->create(['organization_id' => $organization->id, 'name' => 'Deli TV']);
        // The organization's own channel: inside an organization only those are within reach.
        $channel = Channel::factory()->create(['organization_id' => $organization->id, 'name' => 'Alpha Promos']);

        $this->browse(function (Browser $browser) use ($owner, $organization, $screen, $channel) {
            $this->freshSession($browser);
            $browser->loginAs($owner);
            $this->switchToOrganization($browser, $organization);

            $this->walk($browser, [
                '/dashboard',
                '/screens',
                '/screens/'.$screen->id,     // one screen's playlist page
                '/media',
                '/channels',
                '/channels/'.$channel->id,   // one channel's ads
                '/builder',                  // the Ad Builder's two pages: the ads, whose New ad asks the shape…
                '/builder/assets',           // …and the shelf…
                '/builder/create?orientation=landscape',  // …then the editor, on an empty stage of that shape
                '/members',
                '/roles',
                '/activity',
                '/settings/organization',
                '/profile',
            ]);
        });
    }

    /**
     * An organization the platform has paused, as its own people see it, and the app's own error page (owner, 2026-09-30):
     * named, one heading, every control with a name — the error page with no Alpine and no built file of its own.
     */
    public function test_a_paused_organization_and_an_error_page_render_named(): void
    {
        $this->seedSuperAdmin();
        $organization = Organization::factory()->create(['name' => 'Alpha Mart', 'is_active' => false]);
        $owner = $this->organizationMember($organization);

        $this->browse(function (Browser $browser) use ($owner) {
            $this->freshSession($browser);
            $browser->loginAs($owner);

            $this->walk($browser, ['/dashboard']);
            $browser->assertSeeIn('@dashboard-paused', 'Alpha Mart is paused');

            // Every page of it leads back here.
            $browser->visit('/screens')->waitForLocation('/dashboard')->assertPresent('@dashboard-paused');

            $browser->visit('/no-such-page')->assertSee('Page not found')->assertPresent('@error-dashboard');
            $this->assertEverythingIsNamed($browser, '/no-such-page');
            // The address that is not there is the one error its console may show.
            $browser->driver->manage()->getLog('browser');

            $browser->click('@error-dashboard')->waitForLocation('/dashboard')->assertPresent('@dashboard-paused');

            // Both on a phone: nothing wider than it, and the error's button whole on its line.
            $browser->resize(375, 812);
            foreach (['/dashboard', '/no-such-page'] as $page) {
                $browser->visit($page);
                $fits = $browser->script(<<<'JS'
                    const button = document.querySelector('[dusk="error-dashboard"]');
                    return {
                        sideways: document.documentElement.scrollWidth > window.innerWidth + 1,
                        buttonOneLine: !button || button.getBoundingClientRect().height <= 44,
                    };
                JS)[0];
                $this->assertFalse($fits['sideways'], "{$page} is wider than a phone");
                $this->assertTrue($fits['buttonOneLine'], "{$page}'s button wraps on a phone");
            }
            $browser->driver->manage()->getLog('browser');
            $browser->resize(1920, 1080);
        });
    }

    /** Open each page, let Alpine settle, and insist that it finished loading and said nothing to the console. */
    private function walk(Browser $browser, array $pages): void
    {
        foreach ($pages as $page) {
            $browser->visit($page);

            try {
                $this->waitForAlpine($browser);
            } catch (\Throwable) {
                $this->fail("Alpine never initialised on {$page} — the browser is at ".$browser->driver->getCurrentURL());
            }

            // Whatever the page lists has to arrive: a listing that never resolves keeps this word.
            $browser->waitUntilMissingText('Loading...', 8)
                ->assertDontSee('Server Error')
                ->assertDontSee('Whoops')
                ->assertDontSee('is not defined')
                ->assertDontSee('Undefined variable');

            $this->assertCleanConsole($browser, $page);
            $this->assertEverythingIsNamed($browser, $page);
        }
    }

    /**
     * What a screen reader needs from every page (rule 02, "Every page speaks to a keyboard and a screen reader"): a
     * tab title of its own, one <h1>, and a name for every control it shows — a button's words or aria-label, a field's
     * label (a placeholder is not one) — and an alt for every picture.
     */
    private function assertEverythingIsNamed(Browser $browser, string $page): void
    {
        $found = $browser->script(<<<'JS'
            const shown = (el) => el.getClientRects().length > 0 && getComputedStyle(el).visibility !== 'hidden';
            const text = (el) => (el ? (el.innerText || el.textContent || '') : '').replace(/\s+/g, ' ').trim();
            const nameOf = (el) => {
                const by = el.getAttribute('aria-labelledby');
                if (by && by.split(/\s+/).map((id) => text(document.getElementById(id))).join('').trim()) return true;
                if ((el.getAttribute('aria-label') || '').trim()) return true;
                if (['INPUT', 'SELECT', 'TEXTAREA'].includes(el.tagName)) {
                    if (['submit', 'button', 'reset'].includes(el.type) && el.value) return true;
                    return [...(el.labels || [])].some((label) => text(label)) || !!(el.title || '').trim();
                }
                return !!(text(el) || [...el.querySelectorAll('img[alt]')].some((img) => img.alt.trim())
                    || text(el.querySelector('svg title')) || (el.title || '').trim());
            };
            const describe = (el) => el.tagName.toLowerCase() + (el.type ? '[' + el.type + ']' : '')
                + (el.getAttribute('dusk') ? ' dusk=' + el.getAttribute('dusk') : '') + ' "' + el.outerHTML.slice(0, 120) + '"';

            return {
                title: document.title,
                h1: [...document.querySelectorAll('h1')].filter(shown).map(text),
                unnamed: [...document.querySelectorAll('button, a[href], input:not([type="hidden"]), select, textarea, [role="tab"], [role="button"], [role="switch"]')]
                    .filter((el) => shown(el) && !nameOf(el)).map(describe),
                noAlt: [...document.querySelectorAll('img')].filter((img) => shown(img) && !img.hasAttribute('alt')).map((img) => img.outerHTML.slice(0, 120)),
            };
        JS)[0];

        $this->assertStringEndsWith(' · '.config('app.name'), $found['title'], "{$page} has no title of its own");
        $this->assertCount(1, $found['h1'], "{$page} should have one h1, it has: ".json_encode($found['h1']));
        $this->assertSame([], $found['unnamed'], "{$page} shows controls a screen reader cannot name");
        $this->assertSame([], $found['noAlt'], "{$page} shows pictures with no alt");
    }

    /** Anything the browser logged as an error since the last check — its own noise aside. */
    private function assertCleanConsole(Browser $browser, string $page): void
    {
        $ignore = ['favicon', 'DevTools', 'chrome-extension'];

        $errors = collect($browser->driver->manage()->getLog('browser'))
            ->filter(fn (array $entry) => ($entry['level'] ?? '') === 'SEVERE')
            ->reject(fn (array $entry) => Str::contains($entry['message'] ?? '', $ignore))
            ->pluck('message')
            ->all();

        $this->assertSame([], $errors, "the console was not clean on {$page}");
    }
}
