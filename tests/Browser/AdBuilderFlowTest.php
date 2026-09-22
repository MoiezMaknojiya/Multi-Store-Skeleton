<?php

namespace Tests\Browser;

use App\Models\BuilderAd;
use App\Models\BuilderAsset;
use App\Models\Media;
use App\Models\PlaylistItem;
use App\Models\Role;
use App\Models\Screen;
use App\Models\Store;
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
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $designer = $this->storeMember(
            $store,
            ['ad-view', 'ad-store', 'ad-update', 'ad-destroy'],
            'designer@example.com',
            'Designer',
        );

        $this->browse(function (Browser $browser) use ($designer, $store) {
            $this->freshSession($browser);
            $browser->loginAs($designer);
            $this->switchToStore($browser, $store);

            /* ── 1. The section, and its three tabs ─────────────────────── */
            $browser->visit('/builder');
            $this->waitForAlpine($browser);
            $browser->waitFor('@builder-tabs')
                ->assertVisible('@builder-tab-create')
                ->assertVisible('@builder-tab-ads')
                ->assertVisible('@builder-tab-assets')
                ->waitFor('@ads-empty');

            /* ── 2. A new ad: the stage is there, and it is a television's ─ */
            $this->clickAndAwait($browser, '@new-ad', fn (Browser $b) => $b->waitFor('@ad-stage', 5));
            $this->waitForAlpine($browser);
            $browser->assertSee('1920 × 1080');

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

            $this->assertSame($store->id, $ad->store_id);
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
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $designer = $this->storeMember($store, ['ad-view', 'ad-store', 'ad-update'], 'designer@example.com', 'Designer');
        $ad = BuilderAd::factory()->withText()->create(['store_id' => $store->id, 'name' => 'Winter sale']);

        $this->browse(function (Browser $browser) use ($designer, $store, $ad) {
            $this->freshSession($browser);
            $browser->loginAs($designer);
            $this->switchToStore($browser, $store);

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
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $designer = $this->storeMember(
            $store,
            ['ad-view', 'ad-store', 'ad-update', 'ad-destroy'],
            'designer@example.com',
            'Designer',
        );

        $this->browse(function (Browser $browser) use ($designer, $store) {
            $this->freshSession($browser);
            $browser->loginAs($designer);
            $this->switchToStore($browser, $store);

            /* ── 1. A picture onto the shelf ────────────────────────────── */
            $browser->visit('/builder/assets');
            $this->waitForAlpine($browser);
            $browser->waitFor('@assets-empty')
                ->assertVisible('@upload-asset')
                ->attach('@asset-file', $this->fixtureImage('builder-logo.png', 30, 120, 220));

            $browser->waitUsing(20, 250, fn () => BuilderAsset::where('store_id', $store->id)->exists());
            $asset = BuilderAsset::firstWhere('store_id', $store->id);
            $browser->waitFor('@asset-card-'.$asset->id)
                ->assertSeeIn('@asset-usage-'.$asset->id, 'Not used yet');

            /* ── 2. Used in an ad, beside a shape ───────────────────────── */
            $browser->visit('/builder/create');
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

            $this->clickAndAwait($browser, '@delete-asset-'.$asset->id, fn (Browser $b) => $b->waitFor('@confirm-asset-deletion-confirm', 3));
            $this->jsClick($browser, '@confirm-asset-deletion-confirm');

            // The refusal names the ad (BuilderAssetController::destroy) — which also means the
            // server has answered, so the row below is read after the delete was decided.
            $browser->waitForText('Still used by Poster ad');

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
     * Above the stores the shelf belongs to no shop of its own, so an upload goes to the shop chosen in
     * the Shop list — and with "All shops" chosen there is nowhere for it to go, which the page says.
     */
    public function test_the_platform_uploads_a_picture_to_the_shop_chosen_in_the_shop_list(): void
    {
        $admin = $this->seedSuperAdmin();
        Store::factory()->create(['name' => 'Alpha Mart']);
        $beta = Store::factory()->create(['name' => 'Beta Deli']);
        $picture = $this->fixtureImage('platform-logo.png', 200, 60, 30);

        $this->browse(function (Browser $browser) use ($admin, $beta, $picture) {
            $this->freshSession($browser);
            $browser->loginAs($admin)->visit('/builder/assets');
            $this->waitForAlpine($browser);
            $browser->waitFor('@assets-empty')
                ->assertSelected('@assets-filter-store', '');      // "All shops"

            /* ── 1. All shops: refused, with the reason ────────────────── */
            $browser->attach('@asset-file', $picture)
                ->waitForText("Choose the shop in the Shop list first — an ad's pictures belong to one shop.");
            $this->assertSame(0, BuilderAsset::count(), 'a picture was put on a shelf nobody chose');

            /* ── 2. One shop chosen: the picture goes onto its shelf ───── */
            $browser->select('@assets-filter-store', (string) $beta->id)
                ->waitFor('@assets-empty');                         // Beta's shelf, loaded and empty
            $browser->attach('@asset-file', $picture);

            $browser->waitUsing(20, 250, fn () => BuilderAsset::count() === 1);
            $asset = BuilderAsset::sole();
            $this->assertSame($beta->id, $asset->store_id, 'the picture went to a shop other than the one chosen');

            // Listed on that shop's shelf — and the reload that shows it has come back before the test ends.
            $browser->waitFor('@asset-card-'.$asset->id);
        });
    }

    /**
     * The whole point of the Builder, end to end: a design is published, a shop puts it on a screen,
     * and the television plays it — in a frame of its own, with its own animations, alongside ordinary
     * files. Nothing about the playlist, the schedule or the device knows it was ever a "design".
     */
    public function test_a_published_ad_reaches_a_television(): void
    {
        $this->seedSuperAdmin();
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->storeMember($store, Role::OWNER, 'owner@example.com');
        $ad = BuilderAd::factory()->withText('Winter sale')->create(['store_id' => $store->id, 'name' => 'Winter sale']);

        $this->browse(function (Browser $tv, Browser $panel) use ($owner, $store, $ad) {
            /* ── 1. A television asks to be adopted ─────────────────────── */
            $tv->visit('/player');
            $tv->waitFor('@pairing-code', 15);
            $tv->waitUntil('document.querySelector(\'[dusk="pairing-code"]\').textContent.trim().length === 6', 15);
            $code = trim($tv->text('@pairing-code'));

            /* ── 2. The owner pairs it ──────────────────────────────────── */
            $this->freshSession($panel);
            $panel->loginAs($owner);
            $this->switchToStore($panel, $store);

            $panel->visit('/screens');
            $this->waitForAlpine($panel);
            $this->clickAndAwait($panel, '@add-screen', fn (Browser $b) => $b->waitFor('@screen-pair-form', 3));
            $this->jsType($panel, '@screen-code', $code);
            $this->jsType($panel, '@screen-name', 'Counter TV');
            $this->jsClick($panel, '@screen-pair-save');

            $panel->waitUsing(20, 250, fn () => Screen::where('store_id', $store->id)->exists());
            $screen = Screen::where('store_id', $store->id)->firstOrFail();

            /* ── 3. Publish the design ──────────────────────────────────── */
            $panel->visit('/builder/'.$ad->id);
            $this->waitForAlpine($panel);
            $panel->waitFor('@ad-publish');
            $this->jsClick($panel, '@ad-publish');

            $panel->waitUsing(25, 250, fn () => Media::where('store_id', $store->id)->where('type', Media::TYPE_HTML)->exists());
            $media = Media::where('type', Media::TYPE_HTML)->firstOrFail();

            $this->assertSame($media->id, $ad->fresh()->media_id, 'the ad now has a copy in the library');

            /* ── 4. Put it on the screen, like any other file ───────────── */
            $panel->visit('/screens/'.$screen->id);
            $this->waitForAlpine($panel);
            $panel->waitForText('Winter sale');          // the picker lists the published ad

            $this->jsClick($panel, '@playlist-add-'.$media->id);

            // An ad page is timed like a picture: its line carries the seconds, and they can be changed.
            $panel->waitFor('@playlist-duration-0')->assertMissing('@playlist-length-0');
            $this->jsType($panel, '@playlist-duration-0', '15');
            $this->jsClick($panel, '@playlist-save');

            $panel->waitUsing(15, 250, fn () => PlaylistItem::where('screen_id', $screen->id)->count() === 1);
            $this->assertSame(15, PlaylistItem::where('screen_id', $screen->id)->sole()->duration_seconds);

            /* ── 5. The television plays it ─────────────────────────────── */
            $tv->waitUntil('!!document.querySelector("#layer-a iframe, #layer-b iframe")', 45);

            $source = $tv->script('return document.querySelector("#layer-a iframe, #layer-b iframe").getAttribute("src");')[0];
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
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $designer = $this->storeMember(
            $store,
            ['ad-view', 'ad-store', 'ad-update', 'media-view', 'screen-view', 'screen-playlist'],
            'designer@example.com',
            'Designer',
        );
        $ad = BuilderAd::factory()->withText('Winter sale')->create(['store_id' => $store->id, 'name' => 'Winter sale']);
        $page = app(AdPublisher::class)->publish($ad);
        $screen = Screen::factory()->create(['store_id' => $store->id, 'name' => 'Counter TV']);
        PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $page->id, 'position' => 0, 'duration_seconds' => 10]);

        $this->browse(function (Browser $browser) use ($designer, $store, $ad, $page, $screen) {
            $this->freshSession($browser);
            $browser->loginAs($designer);
            $this->switchToStore($browser, $store);

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

            $browser->visit('/media');
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

            /* ── 4. Unpublish: off the screens — the library and the playlist say so ── */
            $this->jsClick($browser, '@ad-publish-menu');
            $browser->waitFor('@publish-menu');
            $this->jsClick($browser, '@ad-unpublish');
            $browser->waitFor('@confirm-unpublish');
            $this->jsClick($browser, '@confirm-unpublish');
            $browser->waitForText('Unpublished — taken off 1 screen')
                ->waitForTextIn('@publication-status', 'Draft · not on screens');
            $this->assertSame('publish', strtolower(trim($browser->text('@ad-publish'))));
            $this->assertFalse($ad->fresh()->isPublished());

            $browser->visit('/media');
            $this->waitForAlpine($browser);
            $browser->waitForText('No media found.');

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
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $designer = $this->storeMember($store, ['ad-view', 'ad-store', 'ad-update'], 'designer@example.com', 'Designer');
        $ad = BuilderAd::factory()->withText('Winter sale')->create(['store_id' => $store->id, 'name' => 'Winter sale']);
        app(AdPublisher::class)->publish($ad);

        $this->browse(function (Browser $browser) use ($designer, $store, $ad) {
            $this->freshSession($browser);
            $browser->loginAs($designer);
            $this->switchToStore($browser, $store);
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
                    ->assertSeeIn('@toolbar-more-rulers', 'Rulers and guides')
                    ->assertSeeIn('@toolbar-more-shortcuts', 'Keyboard shortcuts');
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
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $designer = $this->storeMember($store, ['ad-view', 'ad-store', 'ad-update'], 'designer@example.com', 'Designer');
        $ad = BuilderAd::factory()->withText('Winter sale')->create(['store_id' => $store->id, 'name' => 'Winter sale']);

        $this->browse(function (Browser $browser) use ($designer, $store, $ad) {
            $this->freshSession($browser);
            $browser->loginAs($designer);
            $this->switchToStore($browser, $store);

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
