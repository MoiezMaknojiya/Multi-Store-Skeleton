<?php

namespace Tests\Browser;

use App\Models\BuilderAd;
use App\Models\Organization;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The Ads page's Status list (owner, 2026-10-22: "Platform aur organization ka selectbox ha wasia hi draft publish ka bhi bana
 * do"): beside the Owner list, All Statuses, Published and Drafts. It narrows the cards, is kept in the address so a reload and
 * the editor's way back land on it, says whose list is empty in the words of both lists, and Show All Statuses lets it go.
 * The list is opened with a real mouse press sent to Chrome, a double one among them.
 */
class AdStatusFilterFlowTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_the_status_list_narrows_the_ads_and_is_kept_in_the_address(): void
    {
        $admin = $this->seedSuperAdmin();
        $alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
        $draft = BuilderAd::factory()->create(['organization_id' => null, 'name' => 'Platform draft']);
        $template = BuilderAd::factory()->published()->create(['organization_id' => null, 'name' => 'Platform template']);
        $alphaLive = BuilderAd::factory()->published()->create(['organization_id' => $alpha->id, 'name' => 'Alpha live']);

        $this->browse(function (Browser $browser) use ($admin, $alpha, $draft, $template, $alphaLive) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/builder');
            $this->waitForAlpine($browser);
            $browser->waitFor('@ad-card-'.$draft->id)->assertVisible('@ad-card-'.$template->id)
                ->assertSelected('@ads-filter-status', '');

            /* ── 1. A double press opens the list and leaves it open; Published narrows the platform's ads to its template ── */
            $select = '[dusk="ads-filter-status"]';
            $this->presses($browser, $this->centreOf($browser, $select), 2);
            $browser->pause(500);
            $this->assertTrue((bool) $browser->script('return document.querySelector('.json_encode($select).').matches(":open");')[0]);
            $this->key($browser, 'Escape');

            $browser->select('@ads-filter-status', 'published')
                // A filter loads the list afresh: the cards go while it loads, so the one kept is waited for.
                ->waitUntilMissing('@ad-card-'.$draft->id)->waitFor('@ad-card-'.$template->id)
                ->assertSeeIn('@ad-card-'.$template->id, 'Published');
            $this->assertStringContainsString('status=published', $browser->driver->getCurrentURL());

            /* ── 2. A reload opens on the same list ── */
            $browser->refresh();
            $this->waitForAlpine($browser);
            $browser->waitFor('@ad-card-'.$template->id)->assertSelected('@ads-filter-status', 'published')
                ->assertMissing('@ad-card-'.$draft->id);

            /* ── 3. Alpha Mart's drafts: none, said in both lists' words, and Show All Statuses lets the status go ── */
            $browser->select('@ads-filter-organization', (string) $alpha->id)->select('@ads-filter-status', 'draft')
                ->waitFor('@table-no-match')->assertSeeIn('@table-no-match', 'Alpha Mart has no drafts.')
                ->assertVisible('@table-clear-status')->assertMissing('@table-clear-filters');

            $browser->click('@table-clear-status')->waitFor('@ad-card-'.$alphaLive->id)
                ->assertSelected('@ads-filter-status', '')->assertSelected('@ads-filter-organization', (string) $alpha->id);
            $this->assertStringNotContainsString('status=', $browser->driver->getCurrentURL());

            /* ── 4. The platform's drafts: just the draft ── */
            $browser->select('@ads-filter-organization', 'platform')->select('@ads-filter-status', 'draft')
                ->waitUntilMissing('@ad-card-'.$alphaLive->id)->waitFor('@ad-card-'.$draft->id)->pause(500)
                ->assertMissing('@ad-card-'.$template->id)->assertMissing('@ad-card-'.$alphaLive->id)->assertSeeIn('@ad-card-'.$draft->id, 'Draft');
        });
    }

    /** Presses sent to Chrome itself, with the click count a person's double or triple press carries. */
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

    private function key(Browser $browser, string $key): void
    {
        $tools = new ChromeDevToolsDriver($browser->driver);
        foreach (['keyDown', 'keyUp'] as $type) {
            $tools->execute('Input.dispatchKeyEvent', ['type' => $type, 'key' => $key, 'code' => $key, 'windowsVirtualKeyCode' => 27]);
        }
    }

    /** The middle of an element, brought into sight first. */
    private function centreOf(Browser $browser, string $css): array
    {
        return $browser->script('const el = document.querySelector('.json_encode($css).'); el.scrollIntoView({ block: "center" }); '
            .'const r = el.getBoundingClientRect(); return [Math.round(r.left + r.width / 2), Math.round(r.top + r.height / 2)];')[0];
    }
}
