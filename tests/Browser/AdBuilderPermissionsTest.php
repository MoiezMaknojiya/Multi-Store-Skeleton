<?php

namespace Tests\Browser;

use App\Models\BuilderAd;
use App\Models\Organization;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The Ad Builder's editor, for people who hold only some of its permissions (docs/AD-BUILDER-SPEC.md).
 *
 * A role matters only for the permissions it carries, so the editor offers exactly what they allow: Create
 * Ads makes a new ad, Update Ads changes and publishes a saved one, and View Ads is the gallery behind them.
 * A page that offered more would only lead to a refusal — and one that sent somebody who may only create to
 * the saved ad's own address would open a refusal on the next reload.
 */
class AdBuilderPermissionsTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_a_designer_who_may_only_create_saves_a_new_ad_once_and_stays_on_create(): void
    {
        $this->seedSuperAdmin();
        $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
        $maker = $this->organizationMember($organization, ['ad-store'], 'maker@example.com', 'Ad Maker');

        $this->browse(function (Browser $browser) use ($maker, $organization) {
            $this->freshSession($browser);
            $browser->loginAs($maker);
            $this->switchToOrganization($browser, $organization);

            $browser->visit('/builder/create?orientation=landscape');
            $this->waitForAlpine($browser);

            // Publishing changes a saved ad, which is Update Ads — so there is no Publish to press.
            $browser->waitFor('@ad-stage')
                ->assertVisible('@ad-save')
                ->assertMissing('@ad-publish');

            /* ── A new ad saves ─────────────────────────────────────────── */
            $this->jsClick($browser, '@add-shape');
            $browser->waitFor('[dusk^="element-"]');
            $this->jsType($browser, '@ad-name', 'First try');
            $this->jsClick($browser, '@ad-save');

            $browser->waitUsing(20, 250, fn () => BuilderAd::where('name', 'First try')->exists())
                ->waitForText('Ad saved');

            $ad = BuilderAd::firstWhere('name', 'First try');
            $this->assertSame($organization->id, $ad->organization_id);
            $this->assertCount(1, $ad->document['elements']);

            // The saved ad's own address is the editor's, which is Update Ads: somebody who may only
            // create stays on Create, so a reload opens a fresh ad rather than a refusal.
            $this->assertStringEndsWith('/builder/create?orientation=landscape', $browser->driver->getCurrentURL());

            /* ── A second save would change it: refused here, before anything is sent ── */
            $this->countRequests($browser, 'PUT', '/builder/'.$ad->id);
            $this->jsType($browser, '@ad-name', 'Second try');
            $this->jsClick($browser, '@ad-save');

            $browser->waitForText('This ad is saved. Changing a saved ad needs the Update Ads permission, which you do not have.');
            $this->assertSame(0, $this->requestsCounted($browser), 'the change was sent to the server anyway');
            $this->assertSame('First try', $ad->fresh()->name);
            $this->assertStringEndsWith('/builder/create?orientation=landscape', $browser->driver->getCurrentURL());
        });
    }

    public function test_a_designer_without_view_ads_is_offered_no_way_into_the_gallery(): void
    {
        $this->seedSuperAdmin();
        $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
        $designer = $this->organizationMember($organization, ['ad-store', 'ad-update'], 'designer@example.com', 'Designer');
        $ad = BuilderAd::factory()->withText()->create(['organization_id' => $organization->id, 'name' => 'Winter sale']);

        $this->browse(function (Browser $browser) use ($designer, $organization, $ad) {
            $this->freshSession($browser);
            $browser->loginAs($designer);
            $this->switchToOrganization($browser, $organization);

            $browser->visit('/builder/'.$ad->id);
            $this->waitForAlpine($browser);
            $browser->waitFor('@ad-stage');

            // The way out goes to the gallery only for somebody who may see it (View Ads); for anybody
            // else it goes back to the dashboard.
            $browser->assertSeeIn('@builder-exit', 'Back')
                ->assertDontSeeIn('@builder-exit', 'Ads')
                ->assertAttributeContains('@builder-exit', 'href', '/dashboard');

            // Update Ads is enough to preview the saved ad and to publish it.
            $browser->assertVisible('@ad-preview')
                ->assertVisible('@ad-publish');

            // The asset picker works as ever, but offers no road to the Assets page, which is View Ads.
            $this->clickAndAwait($browser, '@add-image', fn (Browser $b) => $b->waitFor('@asset-picker', 3));
            $browser->assertSeeIn('@asset-picker', 'Choose a picture or a video')
                ->assertDontSeeIn('@asset-picker', 'Manage assets');
        });
    }
}
