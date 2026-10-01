<?php

namespace Tests\Browser;

use App\Models\Organization;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The Ad Builder's editor is a script of its own (owner, 2026-09-30: "editor ko alag load karo"): no other page
 * fetches it, the editor's page does and works, and a chunk that cannot be fetched leaves the page standing and
 * says so instead of a blank screen.
 */
class EditorLoadsOnItsOwnTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_only_the_editors_page_fetches_the_editor_and_a_lost_chunk_is_said(): void
    {
        $this->seedSuperAdmin();
        $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
        $designer = $this->organizationMember($organization, ['ad-view', 'ad-store', 'ad-update', 'media-view', 'screen-view'], 'designer@example.com', 'Designer');

        $this->browse(function (Browser $browser) use ($designer, $organization) {
            $this->freshSession($browser);
            $browser->loginAs($designer);
            $this->switchToOrganization($browser, $organization);

            // Every other page runs without it.
            foreach (['/dashboard', '/media', '/screens', '/builder', '/builder/assets'] as $page) {
                $browser->visit($page);
                $this->waitForAlpine($browser);
                $this->assertSame([], $this->editorRequests($browser), "{$page} fetched the editor");
            }

            // The editor's page fetches it and works: an element goes onto the stage.
            $browser->visit('/builder/create?orientation=landscape');
            $this->waitForAlpine($browser);
            $browser->waitFor('@ad-stage');
            $this->assertNotSame([], $this->editorRequests($browser), 'the editor page did not fetch the editor');
            $this->jsClick($browser, '@add-shape');
            $browser->waitFor('[dusk^="element-"]');

            // The chunk cannot be fetched: the page still stands, and says what to do.
            $tools = new ChromeDevToolsDriver($browser->driver);
            $tools->execute('Network.enable');
            $tools->execute('Network.setBlockedURLs', ['urls' => ['*editor-*.js']]);
            $tools->execute('Network.setCacheDisabled', ['cacheDisabled' => true]);

            try {
                $browser->visit('/builder/create?orientation=landscape');
                $browser->waitForText('The editor could not be loaded. Check the connection, then reload the page.', 10)
                    ->assertPresent('@ad-stage');
            } finally {
                $tools->execute('Network.setBlockedURLs', ['urls' => []]);
                $tools->execute('Network.setCacheDisabled', ['cacheDisabled' => false]);
            }

            // Back online, the editor opens again.
            $browser->visit('/builder/create?orientation=landscape');
            $this->waitForAlpine($browser);
            $this->jsClick($browser, '@add-shape');
            $browser->waitFor('[dusk^="element-"]');

            // What the browser logged about the blocked file is this test's own doing.
            $browser->driver->manage()->getLog('browser');
        });
    }

    /** The editor chunks this page asked for. @return list<string> */
    private function editorRequests(Browser $browser): array
    {
        return $browser->script(<<<'JS'
            return performance.getEntriesByType('resource').map((entry) => entry.name).filter((name) => /\/build\/assets\/editor-[^/]+\.js/.test(name));
        JS)[0];
    }
}
