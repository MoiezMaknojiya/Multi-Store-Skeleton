<?php

namespace Tests\Browser;

use App\Models\Permission;
use App\Models\Screen;
use App\Models\Store;
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
 *   2. the browser console stayed clean.
 */
class EveryPageRendersTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_every_page_above_the_stores_renders_with_a_clean_console(): void
    {
        $admin = $this->seedSuperAdmin();
        Store::factory()->create(['name' => 'Alpha Mart']);

        $this->browse(function (Browser $browser) use ($admin) {
            $this->freshSession($browser);
            $browser->loginAs($admin);

            $this->walk($browser, [
                '/dashboard',
                '/users',
                '/stores',
                '/permissions',
                '/roles',
                '/activity',
                '/channels',
                '/campaigns',
                '/profile',
            ]);
        });
    }

    public function test_every_page_inside_a_store_renders_with_a_clean_console(): void
    {
        $this->seedSuperAdmin();
        $store = Store::factory()->create(['name' => 'Alpha Mart']);

        // A custom role of this store holding everything a store's role may hold, so no page is
        // skipped for want of a permission — the Owner role itself carries no channel permissions.
        $owner = $this->storeMember($store, [
            ...Permission::STORE, 'store-view', 'store-store', 'store-destroy',
            'channel-view', 'channel-store', 'channel-update', 'channel-destroy', 'activity-view',
        ], 'owner@example.com', 'Everything');

        $screen = Screen::factory()->create(['store_id' => $store->id, 'name' => 'Deli TV']);

        $this->browse(function (Browser $browser) use ($owner, $store, $screen) {
            $this->freshSession($browser);
            $browser->loginAs($owner);
            $this->switchToStore($browser, $store);

            $this->walk($browser, [
                '/dashboard',
                '/screens',
                '/screens/'.$screen->id,     // one screen's playlist page
                '/media',
                '/dayparts',
                '/channels',
                '/members',
                '/roles',
                '/activity',
                '/settings/store',
                '/profile',
            ]);
        });
    }

    /** Open each page, let Alpine settle, and insist that it finished loading and said nothing to the console. */
    private function walk(Browser $browser, array $pages): void
    {
        foreach ($pages as $page) {
            $browser->visit($page);

            try {
                $this->waitForAlpine($browser);
            } catch (\Throwable $e) {
                $this->fail("Alpine never initialised on {$page} — the browser is at ".$browser->driver->getCurrentURL());
            }

            // Whatever the page lists has to arrive: a listing that never resolves keeps this word.
            $browser->waitUntilMissingText('Loading...', 8)
                ->assertDontSee('Server Error')
                ->assertDontSee('Whoops')
                ->assertDontSee('is not defined')
                ->assertDontSee('Undefined variable');

            $this->assertCleanConsole($browser, $page);
        }
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
