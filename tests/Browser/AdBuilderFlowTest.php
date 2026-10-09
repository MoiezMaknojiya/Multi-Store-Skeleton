<?php

namespace Tests\Browser;

use App\Models\BuilderAd;
use App\Models\BuilderAsset;
use App\Models\Media;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\PlaylistItem;
use App\Models\Role;
use App\Models\Screen;
use App\Models\User;
use App\Services\AdPublisher;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The Ad Builder through the real pages (docs/AD-BUILDER-SPEC.md stage 1a): the three tabs, the editor's
 * stage, adding something to it, moving it, and saving — then finding the design still there afterwards.
 *
 * A drawing tool is the one part of this app a backend test cannot vouch for: the document is written by
 * pointer gestures, so if the stage does not respond, every server test still passes and nothing works.
 */
class AdBuilderFlowTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_a_designer_draws_an_ad_saves_it_and_finds_it_again(): void
    {
        $this->seedSuperAdmin();
        $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
        $designer = $this->organizationMember(
            $organization,
            ['ad-view', 'ad-store', 'ad-update', 'ad-destroy'],
            'designer@example.com',
            'Designer',
        );

        $this->browse(function (Browser $browser) use ($designer, $organization) {
            $this->freshSession($browser);
            $browser->loginAs($designer);
            $this->switchToOrganization($browser, $organization);

            /* ── 1. The section: its ads, and Assets beside them in the sidebar ─ */
            $browser->visit('/builder');
            $this->waitForAlpine($browser);
            // No tabs inside the page (owner, 2026-09-30): Ad Builder's own row opens the ads, and Assets is its
            // sub-link in the sidebar, shown under it while the ads are open.
            $browser->waitFor('@ads-empty')
                ->assertMissing('@builder-tabs')
                ->assertVisible('#main-sidebar a[href$="/builder/assets"]');

            // An old address for a new ad lands on the Ads page with New ad's question open, and asks only once.
            $browser->visit('/builder/create');
            $this->waitForAlpine($browser);
            $browser->waitFor('@new-ad-landscape')->assertVisible('@new-ad-portrait');
            $this->assertStringEndsWith('/builder', $browser->driver->getCurrentURL());
            $this->jsClick($browser, '@new-ad-cancel');
            $browser->waitUntilMissing('@new-ad-landscape');

            /* ── 2. A new ad: which way is the screen? How to start? Then the stage, a television's ─ */
            $this->jsClick($browser, '@new-ad');
            $browser->waitFor('@new-ad-landscape')->assertVisible('@new-ad-portrait');
            $this->jsClick($browser, '@new-ad-landscape');
            $browser->waitFor('@new-ad-own')->assertVisible('@new-ad-premium');
            $this->clickAndAwait($browser, '@new-ad-own', fn (Browser $b) => $b->waitFor('@ad-stage', 5));
            $this->waitForAlpine($browser);
            $browser->assertSee('1920 × 1080')
                ->assertSeeIn('@ad-orientation', 'Landscape');

            $size = $browser->script('const s = document.querySelector(\'[dusk="ad-stage"]\'); return [s.style.width, s.style.height];')[0];
            $this->assertSame(['1920px', '1080px'], $size, 'the stage is the size a television is');

            /* ── 3. Add text: it lands on the stage and in the layers ───── */
            $this->jsClick($browser, '@add-text');
            $browser->waitFor('[dusk^="element-"]')
                ->waitForTextIn('@layers-panel', 'Text')
                ->assertVisible('@properties-panel');

            // The panel edits what is selected: give it real words and a size.
            $this->jsType($browser, '@element-text', 'Winter sale');
            $browser->script('document.querySelector(\'[dusk="element-text"]\').dispatchEvent(new Event("change", { bubbles: true }));');
            $this->jsType($browser, '@element-x', '200');
            $browser->script('document.querySelector(\'[dusk="element-x"]\').dispatchEvent(new Event("change", { bubbles: true }));');

            /* ── 4. Name it and save ────────────────────────────────────── */
            $this->jsType($browser, '@ad-name', 'Winter sale');
            $this->jsClick($browser, '@ad-save');

            $browser->waitUsing(15, 250, fn () => BuilderAd::where('name', 'Winter sale')->exists());
            $ad = BuilderAd::firstWhere('name', 'Winter sale');

            $this->assertSame($organization->id, $ad->organization_id);
            $this->assertSame('Winter sale', $ad->document['elements'][0]['text']);
            $this->assertSame(200, (int) $ad->document['elements'][0]['x']);

            /* ── 5. It is listed, and it opens again with the design in it ─ */
            $browser->visit('/builder');
            $this->waitForAlpine($browser);
            $browser->waitFor('@ad-card-'.$ad->id)
                ->assertSeeIn('@ad-name-'.$ad->id, 'Winter sale')
                ->assertSeeIn('@ad-status-'.$ad->id, 'Draft');

            $browser->visit('/builder/'.$ad->id);
            $this->waitForAlpine($browser);
            $browser->waitFor('[dusk^="element-"]')
                ->waitForTextIn('@layers-panel', 'Text')
                ->assertSee('Winter sale');
        });
    }

    public function test_an_element_is_nudged_undone_and_deleted(): void
    {
        $this->seedSuperAdmin();
        $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
        $designer = $this->organizationMember($organization, ['ad-view', 'ad-store', 'ad-update'], 'designer@example.com', 'Designer');
        $ad = BuilderAd::factory()->withText()->create(['organization_id' => $organization->id, 'name' => 'Winter sale']);

        $this->browse(function (Browser $browser) use ($designer, $organization, $ad) {
            $this->freshSession($browser);
            $browser->loginAs($designer);
            $this->switchToOrganization($browser, $organization);

            $browser->visit('/builder/'.$ad->id);
            $this->waitForAlpine($browser);
            $browser->waitFor('@element-el_text');

            // Selected from the Layers panel: on the stage itself selection happens on pointerdown (so
            // that a click and a drag are one gesture), which a synthetic click does not raise.
            $this->jsClick($browser, '@layer-el_text');
            $browser->waitFor('@properties-panel')->waitFor('[dusk="element-x"]');

            // The editor listens on the window, so the key is raised there — Dusk's keys() needs an
            // element to type into, and the stage is not one. The key handler moves the element
            // there and then, and the panel's field follows before the next command is read.
            $browser->script('window.dispatchEvent(new KeyboardEvent("keydown", { key: "ArrowRight", bubbles: true }));');
            $browser->script('window.dispatchEvent(new KeyboardEvent("keydown", { key: "ArrowRight", bubbles: true }));');

            $x = (int) $browser->value('[dusk="element-x"]');
            $this->assertSame(162, $x, 'two taps of the arrow key move it two pixels');

            // Undo puts it back — both taps at once, being one quick run of the same move.
            $this->jsClick($browser, '@ad-undo');
            $this->assertSame(160, (int) $browser->value('[dusk="element-x"]'));

            // And delete takes it off the stage altogether.
            $this->jsClick($browser, '@element-delete');
            $browser->waitUntilMissing('@element-el_text', 5);
            $browser->assertDontSeeIn('@layers-panel', 'Headline');
        });
    }

    public function test_a_picture_is_uploaded_used_in_an_ad_and_neither_goes_out_from_under_the_other(): void
    {
        $this->seedSuperAdmin();
        $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
        $designer = $this->organizationMember(
            $organization,
            ['ad-view', 'ad-store', 'ad-update', 'ad-destroy'],
            'designer@example.com',
            'Designer',
        );

        $this->browse(function (Browser $browser) use ($designer, $organization) {
            $this->freshSession($browser);
            $browser->loginAs($designer);
            $this->switchToOrganization($browser, $organization);

            /* ── 1. A picture onto the shelf ────────────────────────────── */
            $browser->visit('/builder/assets');
            $this->waitForAlpine($browser);
            $browser->waitFor('@assets-empty')->assertVisible('@upload-asset');
            // Onto the shelf as soon as every byte is in: no Save to press.
            $this->uploadThrough($browser, 'asset', $this->fixtureImage('builder-logo.png', 30, 120, 220));

            $browser->waitUsing(20, 250, fn () => BuilderAsset::where('organization_id', $organization->id)->exists());
            $asset = BuilderAsset::firstWhere('organization_id', $organization->id);
            $browser->waitFor('@asset-card-'.$asset->id)
                ->assertSeeIn('@asset-usage-'.$asset->id, 'Not used yet');

            /* ── 2. Used in an ad, beside a shape ───────────────────────── */
            $browser->visit('/builder/create?orientation=landscape');
            $this->waitForAlpine($browser);
            $browser->waitFor('@ad-stage');

            $this->jsClick($browser, '@add-shape');
            $browser->waitFor('[dusk^="element-"]');

            $this->clickAndAwait($browser, '@add-image', fn (Browser $b) => $b->waitFor('@asset-picker', 3));
            $this->jsClick($browser, '@pick-asset-'.$asset->id);
            $browser->waitUntilMissing('@asset-picker', 5);

            $this->jsType($browser, '@ad-name', 'Poster ad');
            $this->jsClick($browser, '@ad-save');
            $browser->waitUsing(20, 250, fn () => BuilderAd::where('name', 'Poster ad')->exists());

            $ad = BuilderAd::firstWhere('name', 'Poster ad');
            $this->assertCount(2, $ad->document['elements'], 'the shape and the picture both saved');

            /* ── 3. The shelf will not let the picture go while it is used ─ */
            $browser->visit('/builder/assets');
            $this->waitForAlpine($browser);
            $browser->waitFor('@asset-card-'.$asset->id)
                ->assertSeeIn('@asset-usage-'.$asset->id, 'Poster ad');

            // Said at once, naming the ad, before any confirmation: the listing carries destroy's own refusal
            // (in_use_message), so no dialog opens for a delete that could never succeed.
            $this->jsClick($browser, '@delete-asset-'.$asset->id);
            $browser->waitForText('Still used by Poster ad')
                ->assertMissing('@confirm-asset-deletion-confirm');

            $this->assertNotNull(BuilderAsset::find($asset->id), 'a picture an ad uses stays where it is');

            /* ── 4. The ad goes, with the password ──────────────────────── */
            $browser->visit('/builder');
            $this->waitForAlpine($browser);
            $browser->waitFor('@ad-card-'.$ad->id);

            $this->clickAndAwait($browser, '@delete-ad-'.$ad->id, fn (Browser $b) => $b->waitFor('@confirm-ad-deletion-confirm', 3));
            $this->jsType($browser, '@delete-ad-password', 'password');
            $this->jsClick($browser, '@confirm-ad-deletion-confirm');

            $browser->waitUsing(15, 250, fn () => BuilderAd::find($ad->id) === null);

            /* ── 5. And now the picture may go too ──────────────────────── */
            $browser->visit('/builder/assets');
            $this->waitForAlpine($browser);
            $browser->waitFor('@asset-card-'.$asset->id);

            $this->clickAndAwait($browser, '@delete-asset-'.$asset->id, fn (Browser $b) => $b->waitFor('@confirm-asset-deletion-confirm', 3));
            $this->jsClick($browser, '@confirm-asset-deletion-confirm');

            $browser->waitUsing(15, 250, fn () => BuilderAsset::find($asset->id) === null);

            // The shelf reloads itself after a delete. Ending the test with that request still out
            // would have it answered by a database already torn down — a 500 in the NEXT test's
            // console log. The empty shelf is that request come back.
            $browser->waitFor('@assets-empty');
        });
    }

    /**
     * A picture uploaded straight from the editor's picker (owner, 2026-09-30) joins the organization's shelf and is there to
     * pick the moment it is in; without Create Ads the picker takes no file; and above the organizations a new ad is for All
     * organizations, so a platform designer's file goes to the shelf shared with every organization, with no organization to choose first.
     */
    public function test_a_picture_is_uploaded_from_the_editors_picker_and_placed_at_once(): void
    {
        $this->seedSuperAdmin();
        $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
        $designer = $this->organizationMember($organization, ['ad-view', 'ad-store', 'ad-update'], 'designer@example.com', 'Designer');
        $editor = $this->organizationMember($organization, ['ad-view', 'ad-update'], 'editor@example.com', 'Editor');
        $saved = BuilderAd::factory()->create(['organization_id' => $organization->id, 'name' => 'Old poster']);

        // A platform designer: a platform role holding the ordinary Ad Builder permissions.
        $platformDesigner = User::factory()->create(['email' => 'platform-designer@example.com']);
        $platformRole = Role::create(['name' => 'Platform Designer', 'is_global' => true]);
        $platformRole->permissions()->sync(Permission::whereIn('name', ['ad-view', 'ad-store', 'ad-update'])->pluck('id'));
        $platformDesigner->organizations()->attach(0, ['role_id' => $platformRole->id]);

        $this->browse(function (Browser $browser) use ($platformDesigner, $designer, $editor, $organization, $saved) {
            $this->freshSession($browser);
            $browser->loginAs($designer);
            $this->switchToOrganization($browser, $organization);

            $browser->visit('/builder/create?orientation=landscape');
            $this->waitForAlpine($browser);
            $browser->waitFor('@ad-stage');

            $this->clickAndAwait($browser, '@add-image', fn (Browser $b) => $b->waitFor('@asset-picker', 3));
            $browser->assertVisible('@picker-dropzone')
                ->assertSeeIn('@picker-empty', 'No files yet. Drop one above.');

            $this->uploadThrough($browser, 'picker', $this->fixtureImage('Picker logo.png', 220, 40, 90));

            $browser->waitUsing(20, 250, fn () => BuilderAsset::where('organization_id', $organization->id)->exists());
            $asset = BuilderAsset::firstWhere('organization_id', $organization->id);
            $this->assertSame('Picker logo', $asset->title);

            // On the grid at once, first, without leaving the editor.
            $browser->waitFor('@pick-asset-'.$asset->id)->assertMissing('@picker-empty');
            $this->jsClick($browser, '@pick-asset-'.$asset->id);
            $browser->waitUntilMissing('@asset-picker', 5)->waitFor('[dusk^="element-"]');

            $this->jsType($browser, '@ad-name', 'Picked at once');
            $this->jsClick($browser, '@ad-save');
            $browser->waitUsing(20, 250, fn () => BuilderAd::where('name', 'Picked at once')->exists());
            $this->assertSame($asset->id, BuilderAd::firstWhere('name', 'Picked at once')->document['elements'][0]['assetId'] ?? null);

            // Without Create Ads the picker takes no file: the shelf's upload asks for it, as its route does.
            $this->freshSession($browser);
            $browser->loginAs($editor);
            $this->switchToOrganization($browser, $organization);
            $browser->visit('/builder/'.$saved->id);
            $this->waitForAlpine($browser);
            $browser->waitFor('@ad-stage');
            $this->clickAndAwait($browser, '@add-image', fn (Browser $b) => $b->waitFor('@asset-picker', 3));
            $browser->waitFor('@pick-asset-'.$asset->id)->assertMissing('@picker-upload');

            // Above the organizations a new ad that Create Ad named no organization for is the platform's (owner, 2026-10-01 and
            // 2026-10-09): the file goes to the shelf shared with every organization at once, and nothing asks for an organization first.
            $this->freshSession($browser);
            $browser->loginAs($platformDesigner)->visit('/builder/create?orientation=landscape');
            $this->waitForAlpine($browser);
            $browser->waitFor('@ad-stage');
            $this->clickAndAwait($browser, '@add-image', fn (Browser $b) => $b->waitFor('@asset-picker', 3));
            $this->uploadThrough($browser, 'picker', $this->fixtureImage('Shared logo.png'));

            $browser->waitUsing(20, 250, fn () => BuilderAsset::whereNull('organization_id')->exists());
            $shared = BuilderAsset::whereNull('organization_id')->sole();
            $this->assertSame('Shared logo', $shared->title);
            $browser->waitFor('@pick-asset-'.$shared->id)->assertDontSee('Choose the organization this ad is for first');
        });
    }

    /**
     * The owner's own steps (2026-10-01): above the organizations a new ad is the platform's, a picture dropped into the picker
     * goes to the platform's shelf at once — no "choose the organization first" — and once the ad is published an organization's
     * designer finds it under Create Ad's Premium Template (2026-10-07), uses it, and works on the copy as their own, its picture
     * copied onto their own shelf.
     */
    public function test_the_platform_makes_an_ad_for_every_organization_and_an_organization_uses_it_as_a_template(): void
    {
        $admin = $this->seedSuperAdmin();
        $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
        $designer = $this->organizationMember($organization, ['ad-view', 'ad-store', 'ad-update'], 'designer@example.com', 'Designer');

        $this->browse(function (Browser $browser) use ($admin, $organization, $designer) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/builder/create?orientation=landscape');
            $this->waitForAlpine($browser);
            $browser->waitFor('@ad-stage');

            // With no organization named, the new ad is the platform's, and the editor says so beside the shape: no list to change it.
            $browser->assertSeeIn('@ad-owner', 'Platform')->assertMissing('@ad-organization');

            $this->clickAndAwait($browser, '@add-image', fn (Browser $b) => $b->waitFor('@asset-picker', 3));
            $this->uploadThrough($browser, 'picker', $this->fixtureImage('Brand logo.png', 30, 90, 200));

            $browser->waitUsing(20, 250, fn () => BuilderAsset::whereNull('organization_id')->exists());
            $asset = BuilderAsset::whereNull('organization_id')->sole();
            $this->assertSame('Brand logo', $asset->title);

            $browser->waitFor('@pick-asset-'.$asset->id);
            $this->jsClick($browser, '@pick-asset-'.$asset->id);
            $browser->waitUntilMissing('@asset-picker', 5)->waitFor('[dusk^="element-"]');

            $this->jsType($browser, '@ad-name', 'Winter sale');
            $this->jsClick($browser, '@ad-save');
            $browser->waitUsing(20, 250, fn () => BuilderAd::where('name', 'Winter sale')->exists());
            $ad = BuilderAd::firstWhere('name', 'Winter sale');
            $this->assertNull($ad->organization_id);
            $this->assertSame($asset->id, $ad->document['elements'][0]['assetId'] ?? null);

            // Saved, it stays whose it was made for.
            $browser->assertSeeIn('@ad-owner', 'Platform');

            $this->jsClick($browser, '@ad-publish');
            $browser->waitForText('every organization finds it under Premium Template now');
            $browser->waitUsing(20, 250, fn () => $ad->fresh()->isPublished());
            $this->assertNull($ad->fresh()->media->organization_id);

            // An organization's designer does not find it among their ads: it is under Create Ad, a Premium Template.
            $this->freshSession($browser);
            $browser->loginAs($designer);
            $this->switchToOrganization($browser, $organization);
            $browser->visit('/builder');
            $this->waitForAlpine($browser);
            $browser->waitFor('@ads-empty')->assertMissing('@ad-card-'.$ad->id);

            $this->jsClick($browser, '@new-ad');
            $browser->waitFor('@new-ad-landscape');
            $this->jsClick($browser, '@new-ad-landscape');
            $browser->waitFor('@new-ad-premium');
            $this->jsClick($browser, '@new-ad-premium');
            $browser->waitFor('@template-'.$ad->id)->assertSeeIn('@template-'.$ad->id, 'Winter sale')
                ->assertVisible('@template-preview-'.$ad->id)
                ->screenshot('premium-templates');

            $this->clickAndAwait($browser, '@use-template-'.$ad->id, fn (Browser $b) => $b->waitFor('@ad-stage', 10));
            $this->waitForAlpine($browser);
            $copy = BuilderAd::where('organization_id', $organization->id)->sole();
            $this->assertSame('Winter sale', $copy->name);
            $this->assertStringEndsWith('/builder/'.$copy->id, $browser->driver->getCurrentURL());

            // The copy is theirs, and so is its picture: a copy on their own shelf, the one on the stage.
            $own = BuilderAsset::where('organization_id', $organization->id)->sole();
            $this->assertSame($asset->id, $own->copied_from_id);
            $this->assertSame($own->id, $copy->document['elements'][0]['assetId'] ?? null);
            $browser->assertMissing('@ad-owner')->waitFor('[dusk^="element-"]');
            $this->clickAndAwait($browser, '@add-image', fn (Browser $b) => $b->waitFor('@asset-picker', 3));
            $browser->waitFor('@pick-asset-'.$own->id)->assertMissing('@pick-asset-'.$asset->id);
        });
    }

    /**
     * Above the organizations an upload goes to the organization chosen in the Organization list — or, with none chosen, it is the
     * platform's (owner, 2026-09-29), for its ads for every organization: an organization's designer finds neither the platform's file
     * nor another organization's on their shelf or in the editor's picker (owner, 2026-10-07: their own files alone).
     */
    public function test_the_platform_keeps_a_picture_for_itself_or_gives_it_to_the_organization_chosen(): void
    {
        $admin = $this->seedSuperAdmin();
        $alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
        $beta = Organization::factory()->create(['name' => 'Beta Deli']);
        $brandPicture = $this->fixtureImage('Brand logo.png', 200, 60, 30);
        $menuPicture = $this->fixtureImage('Beta menu.png', 30, 60, 200);
        $designer = $this->organizationMember($alpha, ['ad-view', 'ad-store', 'ad-update', 'ad-destroy'], 'designer@example.com', 'Designer');

        $this->browse(function (Browser $browser) use ($admin, $alpha, $beta, $brandPicture, $menuPicture, $designer) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/builder/assets');
            $this->waitForAlpine($browser);
            $browser->waitFor('@assets-empty')
                ->assertSelected('@assets-filter-organization', 'platform')      // the platform's own shelf, with no wall (owner, 2026-10-08)
                ->assertMissing('@storage-meter')
                ->assertMissing('@assets-upload-target');

            /* ── 1. Platform: the picture is the platform's ────── */
            $this->uploadThrough($browser, 'asset', $brandPicture);
            $browser->waitUsing(20, 250, fn () => BuilderAsset::count() === 1);
            $brand = BuilderAsset::sole();
            $this->assertNull($brand->organization_id, 'the picture did not stay the platform\'s');
            $browser->waitFor('@asset-card-'.$brand->id)->assertSeeIn('@asset-owner-'.$brand->id, 'Platform');

            // All lists everybody's, and says where a new file goes.
            $browser->select('@assets-filter-organization', '')
                ->waitFor('@assets-upload-target')->assertSeeIn('@assets-upload-target', 'New files go to the Platform.')
                ->waitFor('@asset-card-'.$brand->id)->assertMissing('@storage-meter');

            /* ── 2. One organization chosen: the picture is that organization's alone ───── */
            $browser->select('@assets-filter-organization', (string) $beta->id)
                ->waitUntilMissing('@asset-card-'.$brand->id)       // an organization's shelf is its own files alone
                ->waitFor('@storage-meter');                        // and the organization's own 512 MB where the note once was
            $this->uploadThrough($browser, 'asset', $menuPicture);
            $browser->waitUsing(20, 250, fn () => BuilderAsset::count() === 2);
            $menu = BuilderAsset::where('title', 'Beta menu')->sole();
            $this->assertSame($beta->id, $menu->organization_id, 'the picture went to an organization other than the one chosen');
            $browser->waitFor('@asset-card-'.$menu->id)->assertSeeIn('@asset-owner-'.$menu->id, 'Beta Deli');

            /* ── 3. Alpha's designer: neither the platform's file nor Beta's is on their shelf ── */
            $this->freshSession($browser);
            $browser->loginAs($designer);
            $this->switchToOrganization($browser, $alpha);
            $browser->visit('/builder/assets');
            $this->waitForAlpine($browser);
            $browser->waitFor('@assets-empty')
                ->assertMissing('@asset-card-'.$brand->id)
                ->assertMissing('@asset-card-'.$menu->id);

            /* ── 4. …nor in the editor's picker ─────────────────────────── */
            $browser->visit('/builder/create?orientation=landscape');
            $this->waitForAlpine($browser);
            $browser->waitFor('@ad-stage');
            $this->clickAndAwait($browser, '@add-image', fn (Browser $b) => $b->waitFor('@asset-picker', 3));
            $browser->pause(300)->assertMissing('@pick-asset-'.$brand->id)->assertMissing('@pick-asset-'.$menu->id);
        });
    }

    /**
     * The whole point of the Builder, end to end: a design is published, an organization puts it on a screen,
     * and the television plays it — in a frame of its own, with its own animations, alongside ordinary
     * files. Nothing about the playlist, the schedule or the device knows it was ever a "design".
     */
    public function test_a_published_ad_reaches_a_television(): void
    {
        $this->seedSuperAdmin();
        $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->organizationMember($organization, Role::OWNER, 'owner@example.com');
        $ad = BuilderAd::factory()->withText('Winter sale')->create(['organization_id' => $organization->id, 'name' => 'Winter sale']);

        $this->browse(function (Browser $tv, Browser $panel) use ($owner, $organization, $ad) {
            /* ── 1. A television asks to be adopted ─────────────────────── */
            $tv->visit('/player');
            $tv->waitFor('@pairing-code', 15);
            $tv->waitUntil('document.querySelector(\'[dusk="pairing-code"]\').textContent.trim().length === 6', 15);
            $code = trim($tv->text('@pairing-code'));

            /* ── 2. The owner pairs it ──────────────────────────────────── */
            $this->freshSession($panel);
            $panel->loginAs($owner);
            $this->switchToOrganization($panel, $organization);

            $panel->visit('/screens');
            $this->waitForAlpine($panel);
            $this->clickAndAwait($panel, '@add-screen', fn (Browser $b) => $b->waitFor('@screen-pair-form', 3));
            $this->jsType($panel, '@screen-code', $code);
            $this->jsType($panel, '@screen-name', 'Counter TV');
            $this->jsClick($panel, '@screen-pair-save');

            $panel->waitUsing(20, 250, fn () => Screen::where('organization_id', $organization->id)->exists());
            $screen = Screen::where('organization_id', $organization->id)->firstOrFail();

            /* ── 3. Publish the design ──────────────────────────────────── */
            $panel->visit('/builder/'.$ad->id);
            $this->waitForAlpine($panel);
            $panel->waitFor('@ad-publish');
            $this->jsClick($panel, '@ad-publish');

            $panel->waitUsing(25, 250, fn () => Media::where('organization_id', $organization->id)->where('type', Media::TYPE_HTML)->exists());
            $media = Media::where('type', Media::TYPE_HTML)->firstOrFail();

            $this->assertSame($media->id, $ad->fresh()->media_id, 'the ad now has a copy in the library');

            /* ── 4. Published, it is in the screen's Content library at once, like any other file ── */
            // Owner, 2026-10-01: publishing alone puts an ad in the Content library and in the channels' pickers —
            // there is no tick — and a channel showing it would keep it off the playlist (2026-09-26).
            $panel->visit('/screens/'.$screen->id);
            $this->waitForAlpine($panel);
            $panel->waitForText('Winter sale');          // the picker lists the published ad

            $this->jsClick($panel, '@playlist-add-'.$media->id);

            // An ad page plays for the length its design says (owner, 2026-09-28): its line shows it and has no
            // seconds to set, as a video's has none.
            $panel->waitFor('@playlist-length-0')->assertMissing('@playlist-duration-0');
            $this->jsClick($panel, '@playlist-save');

            $panel->waitUsing(15, 250, fn () => PlaylistItem::where('screen_id', $screen->id)->count() === 1);
            $this->assertSame(BuilderAd::DEFAULT_SECONDS, PlaylistItem::where('screen_id', $screen->id)->sole()->duration_seconds);

            /* ── 6. The television plays it ─────────────────────────────── */
            $tv->waitUntil('!!document.querySelector("#layer-a iframe, #layer-b iframe")', 45);

            // The frame opens the page inside the worker's scope when a worker keeps the set, and straight from
            // the server when none does (docs §15); either way it says which page it holds.
            $source = $tv->script('return document.querySelector("#layer-a iframe, #layer-b iframe").dataset.src;')[0];
            $this->assertStringContainsString('/builder/', $source, 'the frame is showing the published page');
            $tv->waitUntilMissingText('No content');
        });
    }

    /**
     * The industry's draft/publish model through the editor (docs/AD-BUILDER-SPEC.md §9): a change to a published
     * ad is saved as a draft while the screens keep the published version; Discard changes goes back to it;
     * Unpublish takes the ad off the screens, and the library and the playlist say so; Publish brings it back.
     */
    public function test_a_published_ad_is_changed_discarded_unpublished_and_published_again(): void
    {
        $this->seedSuperAdmin();
        $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
        $designer = $this->organizationMember(
            $organization,
            ['ad-view', 'ad-store', 'ad-update', 'media-view', 'screen-view', 'screen-playlist'],
            'designer@example.com',
            'Designer',
        );
        $ad = BuilderAd::factory()->withText('Winter sale')->create(['organization_id' => $organization->id, 'name' => 'Winter sale']);
        $page = app(AdPublisher::class)->publish($ad);
        $screen = Screen::factory()->create(['organization_id' => $organization->id, 'name' => 'Counter TV']);
        PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $page->id, 'position' => 0, 'duration_seconds' => 10]);

        $this->browse(function (Browser $browser) use ($designer, $organization, $ad, $page, $screen) {
            $this->freshSession($browser);
            $browser->loginAs($designer);
            $this->switchToOrganization($browser, $organization);

            /* ── 1. Opened: published and up to date ────────────────────────── */
            $browser->visit('/builder/'.$ad->id);
            $this->waitForAlpine($browser);
            $browser->waitFor('@publication-status')->assertSeeIn('@publication-status', 'Published');
            // The buttons print in capitals (the house button style), so their words are read either way.
            $this->assertStringContainsStringIgnoringCase('publish again', $browser->text('@ad-publish'));

            /* ── 2. Changed and saved: a draft — the screens keep the published version ── */
            $this->jsType($browser, '@ad-name', 'Winter sale 2');
            $browser->script('document.querySelector(\'[dusk="ad-name"]\').dispatchEvent(new Event("change", { bubbles: true }));');
            $browser->waitForTextIn('@publication-status', 'Changes not published');
            $this->assertStringContainsStringIgnoringCase('publish changes', $browser->text('@ad-publish'));
            $this->assertStringContainsString('keep showing the published version', $browser->attribute('@publication-status', 'title'));

            $this->jsClick($browser, '@ad-save');
            $browser->waitForText('Changes saved — the screens keep the published version until you publish them')
                ->assertSeeIn('@publication-status', 'Changes not published');
            $this->assertSame('changed', $ad->fresh()->status());
            $this->assertSame('Winter sale', $page->fresh()->title, 'the library changed before anything was published');

            $browser->visit('/builder');
            $this->waitForAlpine($browser);
            $browser->waitFor('@ad-status-'.$ad->id)->assertSeeIn('@ad-status-'.$ad->id, 'Changes not published');

            // The Media page lists photographs and videos alone (owner, 2026-10-05): the ad's page is the Ad Builder's.
            $browser->visit('/media');
            $this->waitForAlpine($browser);
            $browser->waitForText('No files yet.')->assertDontSee('Winter sale');

            // The screen's playlist keeps the published version, under its published name.
            $browser->visit('/screens/'.$screen->id);
            $this->waitForAlpine($browser);
            $browser->waitForText('Winter sale')->assertDontSee('Winter sale 2');

            /* ── 3. Discard changes: back to the version on the screens ─────── */
            $browser->visit('/builder/'.$ad->id);
            $this->waitForAlpine($browser);
            $browser->waitFor('@ad-publish-menu');
            $this->jsClick($browser, '@ad-publish-menu');
            $browser->waitFor('@publish-menu');
            $this->jsClick($browser, '@ad-discard');
            $browser->waitFor('@confirm-discard-changes');
            $browser->waitForReload(fn (Browser $b) => $this->jsClick($b, '@confirm-discard-changes'));
            $this->waitForAlpine($browser);
            $browser->waitForTextIn('@publication-status', 'Published');
            $this->assertSame('Winter sale', $browser->value('@ad-name'));
            $this->assertSame('published', $ad->fresh()->status());

            /* ── 4. Unpublish: off the screens — the playlist says so ── */
            $this->jsClick($browser, '@ad-publish-menu');
            $browser->waitFor('@publish-menu');
            $this->jsClick($browser, '@ad-unpublish');
            $browser->waitFor('@confirm-unpublish');
            $this->jsClick($browser, '@confirm-unpublish');
            $browser->waitForText('Unpublished — taken off 1 screen')
                ->waitForTextIn('@publication-status', 'Draft · not on screens');
            $this->assertSame('publish', strtolower(trim($browser->text('@ad-publish'))));
            $this->assertFalse($ad->fresh()->isPublished());

            $browser->visit('/screens/'.$screen->id);
            $this->waitForAlpine($browser);
            $browser->waitFor('@playlist-draft-0')
                ->assertSeeIn('@playlist-draft-0', 'not playing until it is published')
                ->screenshot('playlist-draft-line');

            /* ── 5. Published again: back everywhere ───────────────────────── */
            $browser->visit('/builder/'.$ad->id);
            $this->waitForAlpine($browser);
            $browser->waitFor('@ad-publish');
            $this->jsClick($browser, '@ad-publish');
            $browser->waitUsing(25, 250, fn () => $ad->fresh()->isPublished());
            $browser->waitForTextIn('@publication-status', 'Published');

            $browser->visit('/screens/'.$screen->id);
            $this->waitForAlpine($browser);
            $browser->waitForText('Winter sale')->assertMissing('@playlist-draft-0');
        });
    }

    /**
     * On a laptop the bar still holds every control, and draws none over another: below 1280 px the rulers and
     * the shortcuts move into "More", Play and Preview keep only their symbols, and Save and Publish keep their
     * words.
     */
    public function test_the_editor_bar_fits_a_laptop(): void
    {
        $this->seedSuperAdmin();
        $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
        $designer = $this->organizationMember($organization, ['ad-view', 'ad-store', 'ad-update'], 'designer@example.com', 'Designer');
        $ad = BuilderAd::factory()->withText('Winter sale')->create(['organization_id' => $organization->id, 'name' => 'Winter sale']);
        app(AdPublisher::class)->publish($ad);

        $this->browse(function (Browser $browser) use ($designer, $organization, $ad) {
            $this->freshSession($browser);
            $browser->loginAs($designer);
            $this->switchToOrganization($browser, $organization);
            $browser->resize(1100, 800);

            try {
                $browser->visit('/builder/'.$ad->id);
                $this->waitForAlpine($browser);
                $browser->waitFor('@toolbar-more')
                    ->assertMissing('@rulers-toggle')
                    ->assertMissing('@shortcuts-open');
                // The buttons print in capitals (the house button style), so their words are read either way.
                $this->assertStringContainsStringIgnoringCase('save', $browser->text('@ad-save'));
                $this->assertStringContainsStringIgnoringCase('publish', $browser->text('@ad-publish'));

                // Nothing on the bar overlaps its neighbour, and the bar itself does not spill over.
                $overlaps = $browser->script(<<<'JS'
                    const bar = document.querySelector('header');
                    const shown = [...bar.querySelectorAll('button, a, input, [dusk="publication-status"], [dusk="save-status"]')]
                        .filter((el) => el.offsetParent !== null && !el.closest('[dusk="history-panel"], [dusk="toolbar-more-menu"], [dusk="publish-menu"]'))
                        .map((el) => ({ name: el.getAttribute('dusk') || el.textContent.trim(), box: el.getBoundingClientRect() }));
                    const clash = [];
                    for (let i = 0; i < shown.length; i++) {
                        for (let j = i + 1; j < shown.length; j++) {
                            const a = shown[i].box, b = shown[j].box;
                            const inside = (x, y) => x.left >= y.left && x.right <= y.right && x.top >= y.top && x.bottom <= y.bottom;
                            if (inside(a, b) || inside(b, a)) continue;
                            if (a.left < b.right - 1 && b.left < a.right - 1 && a.top < b.bottom - 1 && b.top < a.bottom - 1) {
                                clash.push(shown[i].name + ' × ' + shown[j].name);
                            }
                        }
                    }
                    return { clash, spills: bar.scrollWidth > bar.clientWidth };
                JS)[0];
                $this->assertSame([], $overlaps['clash'], 'controls drawn over each other: '.implode(', ', $overlaps['clash']));
                $this->assertFalse($overlaps['spills'], 'the bar is wider than the window');

                // The rulers and the shortcuts are one click away in "More".
                $this->jsClick($browser, '@toolbar-more');
                $browser->waitFor('@toolbar-more-menu')
                    ->assertSeeIn('@toolbar-more-rulers', 'Rulers and Guides')
                    ->assertSeeIn('@toolbar-more-shortcuts', 'Keyboard Shortcuts');
                $this->jsClick($browser, '@toolbar-more-shortcuts');
                $browser->waitFor('@shortcuts-modal')->screenshot('editor-bar-laptop');
            } finally {
                $browser->resize(1920, 1080);
            }
        });
    }

    public function test_an_ad_is_copied_and_opened_from_the_listing(): void
    {
        $this->seedSuperAdmin();
        $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
        $designer = $this->organizationMember($organization, ['ad-view', 'ad-store', 'ad-update'], 'designer@example.com', 'Designer');
        $ad = BuilderAd::factory()->withText('Winter sale')->create(['organization_id' => $organization->id, 'name' => 'Winter sale']);

        $this->browse(function (Browser $browser) use ($designer, $organization, $ad) {
            $this->freshSession($browser);
            $browser->loginAs($designer);
            $this->switchToOrganization($browser, $organization);

            $browser->visit('/builder');
            $this->waitForAlpine($browser);
            $browser->waitFor('@ad-card-'.$ad->id);

            // Copy: a draft of its own, named so nobody loses track of which is which.
            $this->jsClick($browser, '@duplicate-ad-'.$ad->id);
            $browser->waitUsing(15, 250, fn () => BuilderAd::where('name', 'Winter sale (copy)')->exists());

            $copy = BuilderAd::firstWhere('name', 'Winter sale (copy)');
            $browser->waitFor('@ad-card-'.$copy->id);
            $this->assertEquals($ad->document, $copy->document, 'the copy carries the whole design');

            // Edit opens the copy in the editor, design and all.
            $browser->waitForReload(fn (Browser $b) => $this->jsClick($b, '@edit-ad-'.$copy->id));
            $this->waitForAlpine($browser);
            $browser->waitFor('@ad-stage')->waitFor('@element-el_text');

            $this->assertStringEndsWith('/builder/'.$copy->id, $browser->driver->getCurrentURL());
            $this->assertSame('Winter sale (copy)', $browser->value('@ad-name'));
        });
    }
}
