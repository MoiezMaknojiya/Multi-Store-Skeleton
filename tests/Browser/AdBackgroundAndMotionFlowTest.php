<?php

namespace Tests\Browser;

use App\Models\BuilderAd;
use App\Models\BuilderAsset;
use App\Models\Media;
use App\Models\Store;
use App\Services\MediaStorage;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Stages 3 and 4 through the real editor (docs/AD-BUILDER-SPEC.md §6–§8): a background built from
 * layers, a picture framed and filtered, a shape filled with a gradient, an element that arrives and then
 * floats — previewed with the television's own runtime — and the four example ads opening and playing.
 *
 * The panel writes the document through clicks, so this is where a control that looks right but writes
 * nothing would be caught: every step is checked against what was SAVED, and then against the page a
 * screen would get.
 */
class AdBackgroundAndMotionFlowTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_a_background_is_built_from_layers_and_a_picture_is_framed_and_filtered(): void
    {
        $this->seedSuperAdmin();
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $designer = $this->storeMember($store, ['ad-view', 'ad-store', 'ad-update'], 'designer@example.com', 'Designer');
        $picture = $this->assetOnTheShelf($store, 'Snow pattern');

        $this->browse(function (Browser $browser) use ($designer, $store, $picture) {
            $this->freshSession($browser);
            $browser->loginAs($designer);
            $this->switchToStore($browser, $store);

            $browser->visit('/builder/create');
            $this->waitForAlpine($browser);

            /* ── 1. Nothing selected: the panel is the stage's background ── */
            $browser->waitFor('@stage-panel')->assertSeeIn('@stage-panel', 'build the background here');
            $this->setValue($browser, '@stage-color', '#0b1d3a', 'change');

            /* ── 2. Three layers: a gradient, a colour that multiplies, a tiled picture ── */
            $this->jsClick($browser, '@bg-add-gradient');
            $browser->waitFor('@bg-gradient-kind');
            $this->setValue($browser, '[dusk="bg-gradient-stop-0-color"]', '#ff0000', 'change');
            $this->jsClick($browser, '@bg-gradient-add-stop');
            $browser->waitFor('[dusk="bg-gradient-stop-2-color"]');
            $this->setValue($browser, '[dusk="bg-gradient-stop-1-at"]', '55', 'change');

            $this->jsClick($browser, '@bg-add-color');
            $browser->waitFor('@bg-layer-color');
            $this->setValue($browser, '@bg-layer-blend', 'multiply', 'change');
            $this->setValue($browser, '@bg-layer-opacity', '0.5', 'input');

            $this->clickAndAwait($browser, '@bg-add-image', fn (Browser $b) => $b->waitFor('@asset-picker', 3));
            $browser->assertSeeIn('@asset-picker', 'Choose a picture for the background');
            $this->jsClick($browser, '@pick-asset-'.$picture->id);
            $browser->waitUntilMissing('@asset-picker', 5)->waitFor('@bg-layer-size');
            $this->setValue($browser, '@bg-layer-size', 'custom', 'change');
            $browser->waitFor('@bg-layer-scale');
            $this->setValue($browser, '@bg-layer-scale', '12', 'change');
            $this->setValue($browser, '@bg-layer-repeat', 'repeat', 'change');
            $this->jsClick($browser, '@bg-layer-position-left-top');

            // The stage draws the three layers as the television will.
            $drawn = $browser->script('return [...document.querySelectorAll(\'[dusk^="bg-render-"]\')].map(n => n.getAttribute("style"));')[0];
            $this->assertCount(3, $drawn);
            $this->assertStringContainsString('linear-gradient(135deg', $drawn[0]);
            $this->assertStringContainsString('mix-blend-mode: multiply', $drawn[1]);
            $this->assertStringContainsString('background-size: 12%', $drawn[2]);

            // The colour layer goes to the back, and is hidden.
            $colourId = $browser->script('return Alpine.$data(document.querySelector(\'[x-data^="adEditor"]\')).doc.stage.background.layers.find(l => l.type === "color").id;')[0];
            $this->jsClick($browser, '@bg-layer-down-'.$colourId);
            $this->jsClick($browser, '@bg-layer-down-'.$colourId);
            $this->jsClick($browser, '@bg-layer-visible-'.$colourId);

            /* ── 3. A shape: an ellipse filled with a gradient, with a shadow ── */
            $this->jsClick($browser, '@add-shape');
            $browser->waitFor('@shape-kind-ellipse');
            $this->jsClick($browser, '@shape-kind-ellipse');
            $this->jsClick($browser, '@shape-fill-gradient');
            $browser->waitFor('@shape-gradient-kind');
            $this->setValue($browser, '@shape-gradient-kind', 'radial', 'change');
            $this->jsClick($browser, '@shape-shadow-toggle');
            $browser->waitFor('@shape-shadow-blur');
            $this->setValue($browser, '@shape-shadow-blur', '9999', 'change');     // held at the limit, visibly
            $this->assertSame('500', $browser->value('@shape-shadow-blur'));
            $this->setValue($browser, '@element-blend', 'screen', 'change');

            /* ── 4. A picture: framed, rounded, mirrored, pinned and filtered ── */
            $this->clickAndAwait($browser, '@add-image', fn (Browser $b) => $b->waitFor('@asset-picker', 3));
            $this->jsClick($browser, '@pick-asset-'.$picture->id);
            $browser->waitUntilMissing('@asset-picker', 5)->waitFor('@image-fit');
            $this->setValue($browser, '@image-fit', 'contain', 'change');
            $this->jsClick($browser, '@image-position-right-top');
            $this->setValue($browser, '@image-radius', '24', 'change');
            $this->jsClick($browser, '@image-flip-x');
            $this->jsClick($browser, '@image-border-toggle');
            $browser->waitFor('@image-border-width');
            $this->jsClick($browser, '@image-filters-toggle');
            $browser->waitFor('@image-filter-brightness');
            $this->setValue($browser, '@image-filter-brightness', '120', 'input');
            $this->setValue($browser, '@image-filter-grayscale', '100', 'input');

            // The stage shows the picture the way the page will.
            $pictureStyle = $browser->script('return document.querySelector(\'[dusk^="element-"] img\').getAttribute("style");')[0];
            $this->assertStringContainsString('brightness(120%) grayscale(100%)', $pictureStyle);
            $this->assertStringContainsString('scale(-1, 1)', $pictureStyle);

            /* ── 5. Publish, and read what was saved and what a screen gets ── */
            $this->jsType($browser, '@ad-name', 'Layered');
            $this->jsClick($browser, '@ad-publish');
            $browser->waitUsing(25, 250, fn () => Media::where('type', Media::TYPE_HTML)->exists());

            $document = BuilderAd::firstWhere('name', 'Layered')->document;
            $layers = $document['stage']['background']['layers'];

            $this->assertSame('#0b1d3a', $document['stage']['background']['color']);
            $this->assertSame(['color', 'gradient', 'image'], array_column($layers, 'type'), 'the colour layer went to the back');
            $this->assertFalse($layers[0]['visible']);
            $this->assertSame('multiply', $layers[0]['blend']);
            $this->assertEqualsWithDelta(0.5, $layers[0]['opacity'], 0.001);
            $this->assertSame(['#ff0000', '#9333ea', '#9333ea'], array_column($layers[1]['gradient']['stops'], 'color'));
            $this->assertSame(55, (int) $layers[1]['gradient']['stops'][1]['at']);
            $this->assertSame([$picture->id, 'custom', 12, 'repeat', 'left top'], [
                $layers[2]['assetId'], $layers[2]['size'], (int) $layers[2]['scale'], $layers[2]['repeat'], $layers[2]['position'],
            ]);

            $shape = collect($document['elements'])->firstWhere('type', 'shape')['style'];
            $this->assertSame(['ellipse', 'radial', 500, 'screen'], [
                $shape['shape'], $shape['gradient']['kind'], (int) $shape['shadow']['blur'], $shape['blend'],
            ]);

            $image = collect($document['elements'])->firstWhere('type', 'image')['style'];
            $this->assertSame(['contain', 'right top', 24, true, 120, 100], [
                $image['fit'], $image['position'], (int) $image['radius'], $image['flipX'],
                (int) $image['filters']['brightness'], (int) $image['filters']['grayscale'],
            ]);
            $this->assertIsArray($image['border']);

            $html = Storage::disk('public')->get(Media::sole()->path);

            $this->assertStringContainsString('<div class="ad-bg" style="background-color:#0b1d3a;">', $html);
            $this->assertStringNotContainsString('mix-blend-mode:multiply', $html, 'the hidden layer is not on the page');
            $this->assertStringContainsString('background-size:12%;background-position:left top;background-repeat:repeat;', $html);
            $this->assertStringContainsString('radial-gradient(circle at center', $html);
            $this->assertStringContainsString('filter:brightness(120%) grayscale(100%);transform:scale(-1, 1);', $html);
            $this->assertStringContainsString('mix-blend-mode:screen;', $html);
        });
    }

    public function test_an_element_arrives_then_floats_is_previewed_and_the_published_page_moves(): void
    {
        $this->seedSuperAdmin();
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $designer = $this->storeMember($store, ['ad-view', 'ad-store', 'ad-update'], 'designer@example.com', 'Designer');

        $this->browse(function (Browser $browser) use ($designer, $store) {
            $this->freshSession($browser);
            $browser->loginAs($designer);
            $this->switchToStore($browser, $store);

            $browser->visit('/builder/create');
            $this->waitForAlpine($browser);
            $browser->waitFor('@ad-stage');

            /* ── 1. The owner's example: fade in, then float 10 px for ever ── */
            $this->jsClick($browser, '@add-text');
            $browser->waitFor('@panel-tab-animation');
            $this->jsClick($browser, '@panel-tab-animation');
            $browser->waitFor('@anim-in-effect');

            $this->setValue($browser, '@anim-in-effect', 'fade', 'change');
            $browser->waitFor('@anim-in-duration');
            $this->setValue($browser, '@anim-in-duration', '0.6', 'change');

            $this->setValue($browser, '@anim-loop-effect', 'float', 'change');
            $browser->waitFor('@anim-loop-amount');
            $this->assertSame('10', $browser->value('@anim-loop-amount'), 'a float starts at 10 px');
            $this->setValue($browser, '@anim-loop-duration', '2', 'change');

            // A curve drawn by hand in the Ease Visualizer.
            $this->setValue($browser, '@anim-loop-ease', 'custom', 'change');
            $browser->waitFor('@anim-loop-handle-2');
            // The graph's point (116, 20) is found on screen through the SVG's own transform, the way the
            // editor reads the pointer back: its 200×200 box is drawn centred inside a wider one.
            $browser->script(<<<'JS'
                const handle = document.querySelector('[dusk="anim-loop-handle-2"]');
                const matrix = handle.closest('svg').getScreenCTM();
                const start = handle.getBoundingClientRect();
                const at = (x, y) => {
                    const point = new DOMPoint(x, y).matrixTransform(matrix);

                    return { bubbles: true, pointerId: 1, clientX: point.x, clientY: point.y };
                };
                handle.dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, pointerId: 1, clientX: start.left + 4, clientY: start.top + 4 }));
                window.dispatchEvent(new PointerEvent('pointermove', at(116, 20)));
                window.dispatchEvent(new PointerEvent('pointerup', at(116, 20)));
            JS);
            $browser->waitUsing(5, 100, fn () => str_contains((string) $browser->text('[dusk="anim-loop-curve"] p'), 'cubic(0.25,0.1,0.6,1.3)'));

            // And an exit, six seconds in.
            $this->setValue($browser, '@anim-out-effect', 'zoom', 'change');
            $browser->waitFor('@anim-out-at');
            $this->setValue($browser, '@anim-out-at', '6', 'change');

            /* ── 2. ▶ Play runs the television's runtime over the stage ──── */
            $this->jsClick($browser, '@ad-play');
            $browser->waitFor('@preview-overlay');
            $this->assertStringContainsStringIgnoringCase('stop', $browser->text('@ad-play'));

            $browser->waitUsing(5, 100, fn () => (string) $browser->script(
                'return document.querySelector("[data-anim-id] > .ad-anim").getAttribute("style") || "";'
            )[0] !== '');

            // Esc stops it, and the element is back exactly as it was designed.
            $browser->script('window.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape", bubbles: true }));');
            $browser->waitUntilMissing('@preview-overlay', 5);
            $this->assertStringContainsStringIgnoringCase('play', $browser->text('@ad-play'));
            $this->assertSame('', (string) $browser->script(
                'return document.querySelector("[data-anim-id] > .ad-anim").getAttribute("style") || "";'
            )[0], 'nothing the preview did stays behind');

            /* ── 3. Publish, and open the page a screen would show ───────── */
            $this->jsType($browser, '@ad-name', 'Floating');
            $this->jsClick($browser, '@ad-publish');
            $browser->waitUsing(25, 250, fn () => Media::where('type', Media::TYPE_HTML)->exists());

            $animations = BuilderAd::firstWhere('name', 'Floating')->document['elements'][0]['animations'];

            $this->assertSame('fade', $animations['in']['effect']);
            $this->assertEqualsWithDelta(0.6, $animations['in']['duration'], 0.001);
            $this->assertSame(['float', 'y', 10], [$animations['loop']['effect'], $animations['loop']['axis'], (int) $animations['loop']['amount']]);
            $this->assertSame('cubic(0.25,0.1,0.6,1.3)', $animations['loop']['ease']);
            $this->assertSame(['zoom', 6], [$animations['out']['effect'], (int) $animations['out']['at']]);

            $browser->visit(Storage::disk('public')->url(Media::sole()->path));

            // The runtime took the element over: no longer waiting hidden, and moving — the float
            // starts once the 0.6 s fade is over, and moves the element by a translate.
            $browser->waitUntil('!document.querySelector(".ad-pending")', 10)
                ->waitUntil(
                    '(document.querySelector("[data-anim-id] > .ad-anim").getAttribute("style") || "").includes("translate")',
                    10,
                    'Waited %s seconds for the text to float.'
                );

            $moving = $browser->script(<<<'JS'
                const node = document.querySelector('[data-anim-id] > .ad-anim');
                return { style: node.getAttribute('style') || '', runtime: !!window.AdRuntime, anime: !!window.anime };
            JS)[0];

            $this->assertTrue($moving['runtime'] && $moving['anime'], 'the page loaded Anime.js and the runtime from this server');
            $this->assertStringContainsString('translate', $moving['style'], 'the text is floating');

            // Loaded behind the item that is showing — the way the player prepares the next one — it
            // waits; the moment it is shown, it starts.
            $browser->script(<<<'JS'
                const holder = document.createElement('div');
                holder.id = 'behind-holder';
                holder.style.display = 'none';
                holder.innerHTML = '<iframe id="behind" style="width:960px;height:540px;border:0"></iframe>';
                document.body.appendChild(holder);
                document.getElementById('behind').src = location.href;
            JS);
            $browser->waitUntil('document.getElementById("behind").contentDocument && document.getElementById("behind").contentDocument.readyState === "complete" && !!document.getElementById("behind").contentWindow.AdRuntime', 10);
            $browser->pause(1500);

            $waiting = (int) $browser->script('return document.getElementById("behind").contentDocument.querySelectorAll(".ad-pending").length;')[0];
            $this->assertSame(1, $waiting, 'while it cannot be seen, its entrance has not begun');

            $browser->script('document.getElementById("behind-holder").style.display = "block";');
            $browser->waitUntil('document.getElementById("behind").contentDocument.querySelectorAll(".ad-pending").length === 0', 1);   // well before the 4 s fallback
        });
    }

    public function test_the_example_ads_open_in_the_editor_and_play(): void
    {
        $this->seedSuperAdmin();
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $designer = $this->storeMember($store, ['ad-view', 'ad-store', 'ad-update'], 'designer@example.com', 'Designer');

        Artisan::call('builder:examples', ['store' => $store->id, '--no-fonts' => true, '--no-publish' => true]);
        $this->assertSame(4, BuilderAd::where('store_id', $store->id)->count());

        $this->browse(function (Browser $browser) use ($designer, $store) {
            $this->freshSession($browser);
            $browser->loginAs($designer);
            $this->switchToStore($browser, $store);

            // The browser's log belongs to the browser, which Dusk keeps from one test of this class
            // to the next — reading it empties it, so this test starts from a clean one.
            $browser->driver->manage()->getLog('browser');

            foreach (BuilderAd::where('store_id', $store->id)->orderBy('id')->get() as $ad) {
                $browser->visit('/builder/'.$ad->id);
                $this->waitForAlpine($browser);
                $browser->waitFor('@ad-stage');

                $counts = $browser->script(<<<'JS'
                    return {
                        elements: document.querySelectorAll('[data-anim-id]').length,
                        layers: document.querySelectorAll('[dusk^="bg-render-"]').length,
                    };
                JS)[0];

                $this->assertSame(count($ad->document['elements']), $counts['elements'], "{$ad->name}: every element is on the stage");
                $this->assertSame(count($ad->document['stage']['background']['layers']), $counts['layers'], "{$ad->name}: every layer is drawn");

                // Every picture really arrives from the shelf — a file the shelf could not serve would
                // leave an element on the stage with nothing in it.
                $images = count(array_filter($ad->document['elements'], fn (array $element) => $element['type'] === 'image'));
                $browser->waitUsing(10, 100,
                    fn () => (int) $browser->script('return [...document.querySelectorAll("[data-anim-id] img")].filter(i => i.complete && i.naturalWidth > 0).length;')[0] === $images,
                    str_replace('%', '%%', $ad->name).': not every picture had loaded after %s seconds'
                );

                $this->jsClick($browser, '@ad-play');
                $browser->waitFor('@preview-overlay');

                // ▶ Play hands the elements to the television's runtime, which moves them by their style.
                $browser->waitUsing(5, 100,
                    fn () => (int) $browser->script('return [...document.querySelectorAll("[data-anim-id] > .ad-anim")].filter(n => (n.getAttribute("style") || "") !== "").length;')[0] > 0,
                    str_replace('%', '%%', $ad->name).': ▶ Play had moved nothing after %s seconds'
                );

                $this->jsClick($browser, '@ad-play');
                $browser->waitUntilMissing('@preview-overlay', 5);

                $errors = collect($browser->driver->manage()->getLog('browser'))
                    ->filter(fn (array $entry) => ($entry['level'] ?? '') === 'SEVERE')
                    ->reject(fn (array $entry) => Str::contains($entry['message'] ?? '', ['favicon', 'DevTools']))
                    ->pluck('message')
                    ->all();

                $this->assertSame([], $errors, "{$ad->name}: the console stayed clean");
            }
        });
    }

    public function test_the_smaller_controls_do_what_they_say(): void
    {
        $this->seedSuperAdmin();
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $designer = $this->storeMember($store, ['ad-view', 'ad-store', 'ad-update'], 'designer@example.com', 'Designer');
        $first = $this->assetOnTheShelf($store, 'First picture');
        $second = $this->assetOnTheShelf($store, 'Second picture');

        $this->browse(function (Browser $browser) use ($designer, $store, $first, $second) {
            $this->freshSession($browser);
            $browser->loginAs($designer);
            $this->switchToStore($browser, $store);

            $browser->visit('/builder/create');
            $this->waitForAlpine($browser);
            $browser->waitFor('@stage-panel');

            $editor = 'Alpine.$data(document.querySelector(\'[x-data^="adEditor"]\'))';

            /* ── 1. Background layers: duplicate, delete, a colour stop taken out ── */
            $this->jsClick($browser, '@bg-add-color');
            $browser->waitFor('@bg-layer-color');
            $colourId = $browser->script("return {$editor}.doc.stage.background.layers[0].id;")[0];

            $this->jsClick($browser, '@bg-layer-duplicate-'.$colourId);
            $browser->waitUsing(5, 100, fn () => (int) $browser->script("return {$editor}.doc.stage.background.layers.length;")[0] === 2);

            $this->jsClick($browser, '@bg-layer-delete-'.$colourId);
            $browser->waitUntilMissing('@bg-layer-'.$colourId, 5);

            $this->jsClick($browser, '@bg-add-gradient');
            $browser->waitFor('@bg-gradient-add-stop');
            $this->jsClick($browser, '@bg-gradient-add-stop');
            $browser->waitFor('[dusk="bg-gradient-stop-2-remove"]');
            $this->jsClick($browser, '[dusk="bg-gradient-stop-2-remove"]');
            $browser->waitUntilMissing('[dusk="bg-gradient-stop-2-color"]', 5);

            // ▲ brings the colour copy in front of the gradient.
            $copyId = $browser->script("return {$editor}.doc.stage.background.layers[0].id;")[0];
            $this->jsClick($browser, '@bg-layer-up-'.$copyId);
            $browser->waitUsing(5, 100, fn () => $browser->script("return {$editor}.doc.stage.background.layers[1].id;")[0] === $copyId);

            /* ── 2. A picture replaced in its box, its filters reset ────────── */
            $this->clickAndAwait($browser, '@add-image', fn (Browser $b) => $b->waitFor('@asset-picker', 3));
            $this->jsClick($browser, '@pick-asset-'.$first->id);
            $browser->waitUntilMissing('@asset-picker', 5)->waitFor('@image-replace');

            $before = $browser->script("const e = {$editor}.selected; return [e.id, e.x, e.y, e.w, e.h];")[0];

            $this->clickAndAwait($browser, '@image-replace', fn (Browser $b) => $b->waitFor('@asset-picker', 3));
            $browser->assertSeeIn('@asset-picker', 'Replace the picture');
            $this->jsClick($browser, '@pick-asset-'.$second->id);
            $browser->waitUntilMissing('@asset-picker', 5);

            $after = $browser->script("const e = {$editor}.selected; return [e.id, e.x, e.y, e.w, e.h, e.assetId];")[0];
            $this->assertSame($before, array_slice($after, 0, 5), 'the same box, in the same place');
            $this->assertSame($second->id, $after[5], 'showing the other picture');

            $this->jsClick($browser, '@image-filters-toggle');
            $browser->waitFor('@image-filter-sepia');
            $this->setValue($browser, '@image-filter-sepia', '80', 'input');
            $this->jsClick($browser, '@image-filters-reset');
            $this->assertNull($browser->script("return {$editor}.selected.style.filters ?? null;")[0]);

            /* ── 3. One element previewed from its Animation tab ────────────── */
            $this->jsClick($browser, '@panel-tab-animation');
            $browser->waitFor('@anim-in-effect');
            $this->setValue($browser, '@anim-in-effect', 'zoom', 'change');
            $this->setValue($browser, '@anim-loop-effect', 'pulse', 'change');

            // Every change replays it; the button stops it, and the button starts it again.
            $style = 'return document.querySelector("[data-anim-id] > .ad-anim").getAttribute("style") || "";';
            $browser->waitUsing(5, 100, fn () => (string) $browser->script($style)[0] !== '');
            $this->jsClick($browser, '@anim-preview');
            $browser->waitUsing(5, 100, fn () => (string) $browser->script($style)[0] === '');
            $this->jsClick($browser, '@anim-preview');
            $browser->waitUsing(5, 100, fn () => (string) $browser->script($style)[0] !== '');

            // ▶ on the curve runs its dot.
            $this->jsClick($browser, '@anim-in-curve-toggle');
            $browser->waitFor('@anim-in-try');
            $this->jsClick($browser, '@anim-in-try');
            $browser->waitUsing(5, 100, fn () => $browser->script('return document.querySelector(\'[data-ease-dot="in"]\').getAttribute("cx");')[0] !== '20');

            /* ── 4. Background, at the foot of the layers, shows the stage again ── */
            $this->jsClick($browser, '@layer-background');
            $browser->waitFor('@stage-panel');
            $this->assertNull($browser->script("return {$editor}.selectedId;")[0]);

            // A locked element cannot be picked up, from the stage or from the Layers panel.
            $imageId = $browser->script("return {$editor}.doc.elements.find(e => e.type === 'image').id;")[0];
            $this->jsClick($browser, '@layer-lock-'.$imageId);
            $this->jsClick($browser, '@layer-'.$imageId);
            $this->assertNull($browser->script("return {$editor}.selectedId;")[0], 'a locked element is not selected');

            /* ── 5. What was saved is what was done ─────────────────────────── */
            $this->jsType($browser, '@ad-name', 'Small controls');
            $this->jsClick($browser, '@ad-save');
            $browser->waitUsing(20, 250, fn () => BuilderAd::where('name', 'Small controls')->exists());

            $document = BuilderAd::firstWhere('name', 'Small controls')->document;
            $layers = $document['stage']['background']['layers'];

            $this->assertSame(['gradient', 'color'], array_column($layers, 'type'), 'the copy stayed, the original went, and ▲ put it in front');
            $this->assertNotSame($colourId, $layers[1]['id']);
            $this->assertCount(2, $layers[0]['gradient']['stops']);

            $picture = collect($document['elements'])->firstWhere('type', 'image');
            $this->assertSame($second->id, $picture['assetId']);
            $this->assertTrue($picture['locked']);
            $this->assertNull($picture['style']['filters'] ?? null);
            $this->assertSame(['zoom', 'pulse'], [$picture['animations']['in']['effect'], $picture['animations']['loop']['effect']]);
        });
    }

    /** A real PNG on the store's shelf, stored the way an upload is. */
    private function assetOnTheShelf(Store $store, string $title): BuilderAsset
    {
        $directory = storage_path('framework/testing');
        File::ensureDirectoryExists($directory);
        $path = $directory.DIRECTORY_SEPARATOR.Str::slug($title).'.png';

        $image = imagecreatetruecolor(256, 256);
        imagefilledrectangle($image, 0, 0, 256, 256, imagecolorallocate($image, 200, 220, 255));
        imagepng($image, $path);

        $stored = app(MediaStorage::class)->storeBuilderAsset(new UploadedFile($path, basename($path), 'image/png', null, true), $store->id);

        return BuilderAsset::fromStoredFile($store->id, $title, $stored, null);
    }

    /** Set a field's value through the DOM and raise the event Alpine listens for. */
    private function setValue(Browser $browser, string $selector, string $value, string $event): void
    {
        $css = str_starts_with($selector, '@') ? '[dusk="'.substr($selector, 1).'"]' : $selector;
        $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], $value);

        $browser->script(
            "const el = document.querySelector('{$css}');"
            ."el.value = '{$escaped}';"
            ."el.dispatchEvent(new Event('{$event}', { bubbles: true }));"
        );
    }
}
