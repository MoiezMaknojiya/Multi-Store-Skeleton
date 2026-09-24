<?php

namespace Tests\Browser;

use App\Models\BuilderAd;
use App\Models\Media;
use App\Models\PlaylistItem;
use App\Models\Role;
use App\Models\Screen;
use App\Models\Store;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Storage;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * A portrait ad, end to end (docs/AD-BUILDER-SPEC.md §12): chosen before the editor opens, designed on an
 * upright stage, saved with an upright poster, published — and then played on a television mounted upright,
 * where it fills the glass, and on one the usual way round, where it plays between bars in its own colour
 * while the playlist page says so.
 *
 * A browser test because every piece of this is drawn: the chooser, the stage's shape, the poster's shape,
 * the frame a television gives the page and what the page's own script makes of it.
 */
class AdOrientationFlowTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_a_portrait_ad_is_chosen_up_front_designed_upright_and_fills_a_portrait_television(): void
    {
        $this->seedSuperAdmin();
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->storeMember($store, Role::OWNER, 'owner@example.com');

        $this->browse(function (Browser $tv, Browser $panel) use ($owner, $store) {
            /* ── 1. A television mounted upright asks to be adopted ─────── */
            $this->makeTelevision($tv);
            $tv->visit('/player');
            $tv->waitFor('@pairing-code', 15);
            $tv->waitUntil('document.querySelector(\'[dusk="pairing-code"]\').textContent.trim().length === 6', 15);
            $code = trim($tv->text('@pairing-code'));

            $this->freshSession($panel);
            $panel->loginAs($owner);
            $this->switchToStore($panel, $store);

            $panel->visit('/screens');
            $this->waitForAlpine($panel);
            $this->clickAndAwait($panel, '@add-screen', fn (Browser $b) => $b->waitFor('@screen-pair-form', 3));
            $this->jsType($panel, '@screen-code', $code);
            $this->jsType($panel, '@screen-name', 'Menu TV');
            $panel->select('@screen-orientation', 'portrait');
            $this->jsClick($panel, '@screen-pair-save');

            $panel->waitUsing(20, 250, fn () => Screen::where('store_id', $store->id)->exists());
            $screen = Screen::where('store_id', $store->id)->sole();
            $this->assertSame('portrait', $screen->orientation);

            /* ── 2. The Create tab asks which way the screen is, before any editor opens… ── */
            $panel->visit('/builder');
            $this->waitForAlpine($panel);
            $this->clickAndAwait($panel, '@builder-tab-create', fn (Browser $b) => $b->waitFor('@new-ad-choice', 5));
            $panel->assertVisible('@new-ad-landscape')
                ->assertVisible('@new-ad-portrait')
                ->assertMissing('@ad-stage')
                ->assertSee('which way is the screen')
                ->screenshot('create-tab-orientation-choice');

            /* ── …and so does New ad; Portrait opens an upright stage ── */
            $panel->visit('/builder');
            $this->waitForAlpine($panel);
            $panel->waitFor('@ads-empty');
            $this->jsClick($panel, '@new-ad-empty');
            $panel->waitFor('@new-ad-portrait')
                ->assertVisible('@new-ad-landscape')
                ->assertSee('which way is the screen')
                ->screenshot('new-ad-orientation-chooser');
            $this->clickAndAwait($panel, '@new-ad-portrait', fn (Browser $b) => $b->waitFor('@ad-stage', 5));
            $this->waitForAlpine($panel);

            $panel->assertSeeIn('@ad-orientation', 'Portrait')
                ->assertSeeIn('@stage-size-note', '1080 × 1920');

            $size = $panel->script('const s = document.querySelector(\'[dusk="ad-stage"]\'); return [s.style.width, s.style.height];')[0];
            $this->assertSame(['1080px', '1920px'], $size, 'the stage is a television mounted upright');

            // Drawn upright too: zoomed to fit the window, it stands taller than it is wide.
            $box = $panel->script('const r = document.querySelector(\'[dusk="ad-stage"]\').getBoundingClientRect(); return [r.width, r.height];')[0];
            $this->assertGreaterThan($box[0], $box[1], 'the stage is drawn taller than wide');
            $panel->screenshot('portrait-editor');

            /* ── 3. Text lands inside the narrow frame; the ad saves as portrait, with an upright poster ── */
            $this->jsClick($panel, '@add-text');
            $panel->waitFor('[dusk^="element-"]')->waitForTextIn('@layers-panel', 'Text');
            $this->jsType($panel, '@element-text', 'Menu of the day');
            $panel->script('document.querySelector(\'[dusk="element-text"]\').dispatchEvent(new Event("change", { bubbles: true }));');
            $this->jsType($panel, '@ad-name', 'Menu board');
            $this->jsClick($panel, '@ad-save');

            $panel->waitUsing(15, 250, fn () => BuilderAd::where('name', 'Menu board')->exists());
            $ad = BuilderAd::firstWhere('name', 'Menu board');

            $this->assertSame('portrait', $ad->orientation);
            $this->assertSame([1080, 1920], [(int) $ad->document['stage']['width'], (int) $ad->document['stage']['height']]);

            $text = $ad->document['elements'][0];
            $this->assertGreaterThanOrEqual(0, (int) $text['x'], 'the text box starts inside the frame');
            $this->assertLessThanOrEqual(1080, (int) $text['x'] + (int) $text['w'], 'the text box ends inside the frame');

            $panel->waitUsing(15, 250, fn () => $ad->fresh()->thumbnail_path !== null);
            $poster = getimagesizefromstring((string) Storage::disk('public')->get($ad->fresh()->thumbnail_path));
            $this->assertSame([360, 640], [$poster[0], $poster[1]], 'the poster is upright: 640 px along its longer edge');

            /* ── 4. The gallery says Portrait and shows the whole poster ─ */
            $panel->visit('/builder');
            $this->waitForAlpine($panel);
            $panel->waitFor('@ad-card-'.$ad->id)
                ->assertSeeIn('@ad-orientation-'.$ad->id, 'Portrait');

            $poster = 'document.querySelector(\'[dusk="ad-poster-'.$ad->id.'"]\')';
            $panel->waitUntil("!!{$poster} && {$poster}.complete && {$poster}.naturalWidth > 0", 10);
            $shown = $panel->script("const i = {$poster}; return [i.naturalWidth, i.naturalHeight, getComputedStyle(i).objectFit];")[0];
            $this->assertSame([360, 640, 'contain'], $shown, 'the gallery shows the upright poster, whole, inside the 16:9 tile');
            $panel->screenshot('portrait-ad-in-gallery');

            /* ── 5. Published and opened to playlists ───────────────────── */
            $panel->visit('/builder/'.$ad->id);
            $this->waitForAlpine($panel);
            $panel->waitFor('@ad-publish')->assertSeeIn('@ad-orientation', 'Portrait');
            $this->jsClick($panel, '@ad-publish');
            $panel->waitUsing(25, 250, fn () => Media::where('store_id', $store->id)->where('type', Media::TYPE_HTML)->exists());
            $media = Media::where('type', Media::TYPE_HTML)->sole();
            $this->assertSame('portrait', $media->orientation, 'the library row says which way the page is');
            $this->assertSame([1080, 1920], [$media->width, $media->height]);

            $this->clickAndAwait($panel, '@ad-publish-menu', fn (Browser $b) => $b->waitFor('@ad-in-playlists', 3));
            $this->jsClick($panel, '@ad-in-playlists');
            $panel->waitUsing(15, 250, fn () => (bool) $ad->fresh()->in_playlists);

            /* ── 6. On the upright screen's playlist: the picker says portrait, and nothing warns ── */
            $panel->visit('/screens/'.$screen->id);
            $this->waitForAlpine($panel);
            $panel->waitFor('@playlist-add-'.$media->id)
                ->assertSee('Ad page · portrait')
                ->assertMissing('@picker-orientation-'.$media->id);

            // The picker's thumbnail is the library row's own copy of the upright poster.
            $thumb = 'document.querySelector(\'img[src*="/ads/'.$ad->id.'/published.jpg"]\')';
            $panel->waitUntil("!!{$thumb} && {$thumb}.complete && {$thumb}.naturalWidth > 0", 10);
            $this->assertSame([360, 640], $panel->script("const i = {$thumb}; return [i.naturalWidth, i.naturalHeight];")[0], 'the picker shows the upright poster');

            $this->jsClick($panel, '@playlist-add-'.$media->id);
            $panel->waitFor('@playlist-duration-0')->assertMissing('@playlist-orientation-0');
            $this->jsType($panel, '@playlist-duration-0', '10');
            $this->jsClick($panel, '@playlist-save');
            $panel->waitUsing(15, 250, fn () => PlaylistItem::where('screen_id', $screen->id)->count() === 1);

            /* ── 7. The upright television: the page fills its glass, pixel for pixel ── */
            $layer = $this->waitForAdFrame($tv, $ad);

            $tv->withinFrame("#{$layer} iframe", function (Browser $page) {
                $page->waitFor('#ad-stage', 10)->waitForText('Menu of the day', 10);

                $fit = $this->fitOf($page);

                $this->assertGreaterThan($fit['innerWidth'], $fit['innerHeight'], 'the frame a portrait screen gives the page is upright');
                $this->assertEqualsWithDelta($fit['innerWidth'], $fit['width'], 2, 'the stage fills the frame across');
                $this->assertEqualsWithDelta($fit['innerHeight'], $fit['height'], 2, 'the stage fills the frame down');
                $this->assertEqualsWithDelta(0, $fit['left'], 2, 'no bars at the sides');
                $this->assertEqualsWithDelta(0, $fit['top'], 2, 'no bars above');
            });
            $tv->screenshot('portrait-ad-on-portrait-tv');

            /* ── 8. Turned the usual way round, the same screen plays it between bars — in the stage's own colour ── */
            $screen->update(['orientation' => 'landscape']);

            $panel->visit('/screens/'.$screen->id);
            $this->waitForAlpine($panel);
            $panel->waitFor('@playlist-orientation-0')
                ->assertSeeIn('@playlist-orientation-0', 'Portrait — plays with bars at the sides on this screen')
                ->waitFor('@picker-orientation-'.$media->id)
                ->assertSeeIn('@picker-orientation-'.$media->id, 'bars at the sides')
                // The warning replaces the plain word; it never reads "portrait · Portrait".
                ->assertDontSee('Ad page · portrait')
                ->screenshot('portrait-ad-on-landscape-playlist');

            $tv->refresh();
            $layer = $this->waitForAdFrame($tv, $ad);

            $tv->withinFrame("#{$layer} iframe", function (Browser $page) {
                $page->waitFor('#ad-stage', 10)->waitForText('Menu of the day', 10);

                $fit = $this->fitOf($page);

                $this->assertGreaterThan($fit['innerHeight'], $fit['innerWidth'], 'the frame a landscape screen gives the page lies flat');
                $this->assertEqualsWithDelta($fit['innerHeight'], $fit['height'], 2, 'the stage fills the frame down');
                $this->assertEqualsWithDelta($fit['innerHeight'] * 1080 / 1920, $fit['width'], 2, 'the stage keeps its shape');
                $this->assertEqualsWithDelta(($fit['innerWidth'] - $fit['width']) / 2, $fit['left'], 2, 'centred between the bars');
                $this->assertSame('rgb(15, 23, 42)', $fit['ground'], 'the bars are the stage colour, not black');
            });
            $tv->screenshot('portrait-ad-on-landscape-tv');
        });
    }

    /**
     * A 1920 × 1080 panel, exactly: a browser window of that size shows a smaller page inside its own
     * frame (headless Chrome keeps room for a toolbar it never draws), so the window is widened by the
     * difference until the page itself is the panel — the numbers below are only meaningful on one.
     */
    private function makeTelevision(Browser $tv): void
    {
        $tv->resize(1920, 1080);

        [$width, $height] = $tv->script('return [window.innerWidth, window.innerHeight];')[0];
        $tv->resize(1920 + (1920 - $width), 1080 + (1080 - $height));

        [$width, $height] = $tv->script('return [window.innerWidth, window.innerHeight];')[0];
        $this->assertSame([1920, 1080], [$width, $height], 'the television is a 1920 × 1080 panel');
    }

    /** The layer whose frame is showing this ad's page — waited for, because a television polls. */
    private function waitForAdFrame(Browser $tv, BuilderAd $ad): string
    {
        $layer = null;

        $tv->waitUsing(60, 250, function () use ($tv, $ad, &$layer) {
            $layer = $tv->script(
                'const frame = [...document.querySelectorAll("#layer-a iframe, #layer-b iframe")]'
                .'.find((f) => !f.closest("[id^=layer]").hidden && (f.dataset.src || "").includes("/ads/'.$ad->id.'/"));'
                .'return frame ? frame.closest("[id^=layer]").id : null;'
            )[0];

            return $layer !== null;
        });

        return $layer;
    }

    /**
     * How the page's own script fitted its stage to the frame it was given: the frame's size, the stage's
     * drawn box, and the page's ground colour (what shows beside the stage when the shapes differ).
     *
     * @return array{innerWidth: float, innerHeight: float, width: float, height: float, left: float, top: float, ground: string}
     */
    private function fitOf(Browser $page): array
    {
        return $page->script(<<<'JS'
            const r = document.getElementById('ad-stage').getBoundingClientRect();
            return {
                innerWidth: window.innerWidth, innerHeight: window.innerHeight,
                width: r.width, height: r.height, left: r.left, top: r.top,
                ground: getComputedStyle(document.body).backgroundColor,
            };
        JS)[0];
    }
}
