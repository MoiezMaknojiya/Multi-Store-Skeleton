<?php

namespace Tests\Browser;

use App\Models\BuilderAd;
use App\Models\Organization;
use Facebook\WebDriver\Chrome\ChromeDevToolsDriver;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Above the organizations whose new ad it is is asked with its shape (owner, 2026-10-09: "jab super admin mein ad builder k ander
 * orientation select karte han wahi per organization select karne ka do ander mat do woo hard ha"): Create Ad's For list starts at
 * what the Owner list shows, Landscape or Portrait opens the editor for the one chosen, and the editor only says whose it is.
 * Every press of Create Ad and of a shape is a real mouse press sent to Chrome itself, a double one among them.
 */
class NewAdOwnerFlowTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_the_super_admin_chooses_the_organization_beside_the_shape_and_the_editor_only_says_it(): void
    {
        $admin = $this->seedSuperAdmin();
        $alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
        $beta = Organization::factory()->create(['name' => 'Beta Deli']);

        $this->browse(function (Browser $browser) use ($admin, $alpha, $beta) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/builder');
            $this->waitForAlpine($browser);
            $browser->waitFor('@table-no-match')->assertSelected('@ads-filter-organization', 'platform');

            /* ── 1. The Owner list shows Beta Deli: Create Ad's For starts there, and a double press leaves the question open ── */
            $browser->select('@ads-filter-organization', (string) $beta->id)->waitFor('@table-no-match');
            $this->presses($browser, $this->centreOf($browser, '[dusk="new-ad"]'), 2);
            $browser->waitFor('@new-ad-owner')->pause(400)->assertVisible('@new-ad-dialog')
                ->assertSelected('@new-ad-owner', (string) $beta->id)
                ->assertSeeIn('@new-ad-owner-hint', "Made with this organization's own files");

            /* ── 2. Alpha Mart chosen instead, then Landscape: the editor is Alpha Mart's, with no list to change it ── */
            $browser->select('@new-ad-owner', (string) $alpha->id);
            $this->presses($browser, $this->centreOf($browser, '[dusk="new-ad-landscape"]'), 1);
            $browser->waitFor('@ad-stage', 10);
            $this->waitForAlpine($browser);
            $this->assertStringContainsString('organization_id='.$alpha->id, $browser->driver->getCurrentURL());
            $browser->assertSeeIn('@ad-owner', 'Alpha Mart')->assertMissing('@ad-organization');

            $this->jsType($browser, '@ad-name', 'Alpha poster');
            $this->jsClick($browser, '@ad-save');
            $browser->waitUsing(20, 250, fn () => BuilderAd::where('name', 'Alpha poster')->exists());
            $ad = BuilderAd::firstWhere('name', 'Alpha poster');
            $this->assertSame($alpha->id, $ad->organization_id);
            $this->assertSame('landscape', $ad->orientation);
            $browser->assertSeeIn('@ad-owner', 'Alpha Mart');

            /* ── 3. A choice not used is forgotten: Cancel, and the next Create Ad starts from the Owner list again ── */
            $browser->visit('/builder');
            $this->waitForAlpine($browser);
            $browser->waitFor('@table-no-match')->assertMissing('@ad-card-'.$ad->id);   // the platform's ads, where Alpha's is not
            $this->presses($browser, $this->centreOf($browser, '[dusk="new-ad"]'), 1);
            $browser->waitFor('@new-ad-owner')->assertSelected('@new-ad-owner', 'platform')
                ->select('@new-ad-owner', (string) $beta->id);
            $this->jsClick($browser, '@new-ad-cancel');
            $browser->waitUntilMissing('@new-ad-dialog');
            $this->presses($browser, $this->centreOf($browser, '[dusk="new-ad"]'), 1);
            $browser->waitFor('@new-ad-owner')->pause(300)->assertSelected('@new-ad-owner', 'platform')
                ->assertSeeIn('@new-ad-owner-hint', 'Published, it is a Premium Template every organization can copy.');

            /* ── 4. Platform, then Portrait — pressed twice: the platform's ad, said in the editor ── */
            $this->presses($browser, $this->centreOf($browser, '[dusk="new-ad-portrait"]'), 2);
            $browser->waitFor('@ad-stage', 10);
            $this->waitForAlpine($browser);
            $this->assertStringNotContainsString('organization_id', $browser->driver->getCurrentURL());
            $this->assertStringContainsString('orientation=portrait', $browser->driver->getCurrentURL());
            $browser->assertSeeIn('@ad-owner', 'Platform')->assertMissing('@ad-organization');

            $this->jsType($browser, '@ad-name', 'Every organization poster');
            $this->jsClick($browser, '@ad-save');
            $browser->waitUsing(20, 250, fn () => BuilderAd::where('name', 'Every organization poster')->exists());
            $shared = BuilderAd::firstWhere('name', 'Every organization poster');
            $this->assertNull($shared->organization_id);
            $this->assertSame('portrait', $shared->orientation);

            $this->assertSame([], $this->pageErrors($browser));
        });
    }

    /** Chrome's own log of the page: anything at error level. */
    private function pageErrors(Browser $browser): array
    {
        return collect($browser->driver->manage()->getLog('browser'))
            ->filter(fn (array $entry) => ($entry['level'] ?? '') === 'SEVERE')
            ->reject(fn (array $entry) => str_contains($entry['message'] ?? '', 'favicon'))
            ->pluck('message')->values()->all();
    }

    /** $times presses of a real mouse on one place, 120 ms apart, each counting on from the last as Chrome counts them. */
    private function presses(Browser $browser, array $at, int $times): void
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
                usleep(120 * 1000);
            }
        }
    }

    /** The middle of an element, brought into sight first. */
    private function centreOf(Browser $browser, string $css): array
    {
        return $browser->script('const el = document.querySelector('.json_encode($css).'); el.scrollIntoView({ block: "center" }); '
            .'const r = el.getBoundingClientRect(); return [Math.round(r.left + r.width / 2), Math.round(r.top + r.height / 2)];')[0];
    }
}
