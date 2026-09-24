<?php

namespace Tests\Browser;

use App\Models\BuilderAd;
use App\Models\Media;
use App\Models\Store;
use App\Models\User;
use Facebook\WebDriver\Exception\TimeoutException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Storage;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Stage 5 through the real editor (docs/AD-BUILDER-SPEC.md §10a): selecting many and moving them as one,
 * align and distribute, the clipboard and "paste style", the right-click menu, the Layers panel's drag and
 * rename, rulers and guides, the History panel, autosave, the shortcuts, zoom and pan, the poster and the
 * sandboxed draft preview — and the platform's shop filter.
 *
 * Every gesture is a real pointer or keyboard event dispatched in the page, and every result is read from
 * the document the editor holds or, once saved, from the database.
 */
class AdEditorPolishFlowTest extends DuskTestCase
{
    use DatabaseMigrations;

    /** The editor's component, from inside the page. */
    private const EDITOR = 'Alpine.$data(document.querySelector(\'[x-data^="adEditor"]\'))';

    public function test_many_are_selected_moved_aligned_spaced_and_deleted_together(): void
    {
        [$designer, $store, $ad] = $this->adWith([
            $this->shape('a', 100, 100, 200, 100),
            $this->shape('b', 500, 300, 200, 100),
            $this->shape('c', 1200, 600, 300, 100),
        ]);

        $this->browse(function (Browser $browser) use ($designer, $store, $ad) {
            $this->openEditor($browser, $designer, $store, $ad);

            // A plain click selects one; Shift+click adds another.
            $this->jsClick($browser, '@layer-a');
            $browser->script('document.querySelector(\'[dusk="layer-b"]\').dispatchEvent(new MouseEvent("click", { bubbles: true, shiftKey: true }));');
            $browser->waitFor('@multi-panel')->assertSeeIn('@multi-count', '2');

            // Ctrl+A: all three.
            $this->key($browser, 'a', ['ctrlKey' => true]);
            $browser->waitUntil('document.querySelector(\'[dusk="multi-count"]\').textContent.trim() === "3"', 5);

            // Lined up on their top edge, then spaced evenly across — the outermost two stay put.
            $this->jsClick($browser, '@align-top');
            $this->assertSame([100, 100, 100], $this->column($browser, 'y'));

            $this->jsClick($browser, '@distribute-x');
            $this->assertSame([100, 650, 1200], $this->column($browser, 'x'));

            // Dragging one of them drags all three, by the same amount.
            $this->drag($browser, '@element-b', 37, 43);
            $this->assertSame([137, 687, 1237], $this->column($browser, 'x'));
            $this->assertSame([143, 143, 143], $this->column($browser, 'y'));

            // A marquee on empty stage selects what it touches — here, a alone.
            $this->key($browser, 'Escape');
            $this->marquee($browser, [20, 20], [400, 300]);
            $this->assertSame(['a'], $browser->script('return '.self::EDITOR.'.selectedIds;')[0]);

            // Delete takes the whole selection; one undo brings it all back.
            $this->key($browser, 'a', ['ctrlKey' => true]);
            $this->key($browser, 'Delete');
            $browser->waitUntil(self::EDITOR.'.doc.elements.length === 0', 5);
            $this->key($browser, 'z', ['ctrlKey' => true]);
            $browser->waitUntil(self::EDITOR.'.doc.elements.length === 3', 5);

            $this->saveAndWait($browser);

            $saved = collect($ad->fresh()->document['elements'])->keyBy('id');
            $this->assertSame([137, 687, 1237], [(int) $saved['a']['x'], (int) $saved['b']['x'], (int) $saved['c']['x']]);
        });
    }

    public function test_the_clipboard_pastes_elements_styles_and_animations_and_the_right_click_menu_works(): void
    {
        $headline = $this->text('headline', 100, 100, 'Big red', ['fontSize' => 120, 'color' => '#ff0000'], [
            'in' => ['effect' => 'fade', 'direction' => 'up', 'distance' => 80, 'scale' => 0.6, 'degrees' => -90, 'blur' => 20, 'duration' => 0.8, 'delay' => 0, 'ease' => 'power2.out'],
        ]);

        [$designer, $store, $ad] = $this->adWith([
            $headline,
            $this->text('small', 100, 500, 'Small green', ['fontSize' => 40, 'color' => '#00ff00']),
            $this->shape('box', 900, 300, 300, 200, ['border' => ['width' => 12, 'style' => 'dashed', 'color' => '#ffffff']]),
        ]);

        $this->browse(function (Browser $browser) use ($designer, $store, $ad) {
            $this->openEditor($browser, $designer, $store, $ad);

            // Copy the big red headline; paste its style — and then its animation — onto the small one.
            $this->jsClick($browser, '@layer-headline');
            $this->key($browser, 'c', ['ctrlKey' => true]);
            $browser->waitUntil(self::EDITOR.'.clipboardSize === 1', 5);

            $this->jsClick($browser, '@layer-small');
            $this->key($browser, 'v', ['ctrlKey' => true, 'shiftKey' => true]);
            $this->key($browser, 'v', ['ctrlKey' => true, 'altKey' => true]);

            $small = $this->element($browser, 'small');
            $this->assertSame([120, '#ff0000', 'Small green'], [(int) $small['style']['fontSize'], $small['style']['color'], $small['text']]);
            $this->assertSame('fade', $small['animations']['in']['effect']);

            // Paste: a copy of the headline, 32 px further on, selected.
            $this->key($browser, 'v', ['ctrlKey' => true]);
            $browser->waitUntil(self::EDITOR.'.doc.elements.length === 4', 5);
            $pasted = $browser->script('const e = '.self::EDITOR.'; const p = e.selected; return [p.text, p.x, p.y, p.id !== "headline"];')[0];
            $this->assertSame(['Big red', 132, 132, true], $pasted);

            // Between kinds only what both share crosses over: the shape's frame onto a text.
            $this->jsClick($browser, '@layer-box');
            $this->key($browser, 'c', ['ctrlKey' => true]);
            $this->jsClick($browser, '@layer-headline');
            $this->key($browser, 'v', ['ctrlKey' => true, 'shiftKey' => true]);

            $headline = $this->element($browser, 'headline');
            $this->assertSame(12, (int) $headline['style']['border']['width']);
            $this->assertSame(120, (int) $headline['style']['fontSize'], 'the text keeps its own type');

            // The right-click menu: to the front, then lock, then unlock from the menu the locked one offers.
            $this->rightClick($browser, '@element-box');
            $browser->waitFor('@context-menu')->assertVisible('@menu-front');
            $this->jsClick($browser, '@menu-front');
            $browser->waitUntilMissing('@context-menu', 5);
            $this->assertSame(3, (int) $this->element($browser, 'box')['z']);

            $this->rightClick($browser, '@element-box');
            $browser->waitFor('@menu-lock');
            $this->jsClick($browser, '@menu-lock');
            $browser->waitUntil(self::EDITOR.'.doc.elements.find(e => e.id === "box").locked === true', 5);

            $this->rightClick($browser, '@element-box');
            $browser->waitFor('@menu-unlock');
            $this->jsClick($browser, '@menu-unlock');
            $browser->waitUntil(self::EDITOR.'.doc.elements.find(e => e.id === "box").locked === false', 5);

            // Cut takes it off the stage and onto the clipboard; paste puts a copy back.
            $this->jsClick($browser, '@layer-small');
            $this->key($browser, 'x', ['ctrlKey' => true]);
            $browser->waitUntil(self::EDITOR.'.doc.elements.length === 3', 5);
            $this->key($browser, 'v', ['ctrlKey' => true]);
            $browser->waitUntil(self::EDITOR.'.doc.elements.length === 4', 5);

            $this->saveAndWait($browser);
            $this->assertCount(4, $ad->fresh()->document['elements']);
        });
    }

    public function test_layers_are_dragged_into_order_and_renamed_where_they_are(): void
    {
        [$designer, $store, $ad] = $this->adWith([
            $this->shape('a', 100, 100, 200, 100),
            $this->shape('b', 400, 100, 200, 100),
            $this->shape('c', 700, 100, 200, 100),
        ]);

        $this->browse(function (Browser $browser) use ($designer, $store, $ad) {
            $this->openEditor($browser, $designer, $store, $ad);

            // The panel lists front first (c, b, a); a is dragged above c — to the very front.
            $browser->script(<<<'JS'
                const row = (id) => document.querySelector(`[dusk="layer-${id}"]`);
                const transfer = new DataTransfer();
                const target = row('c').getBoundingClientRect();
                const at = { bubbles: true, cancelable: true, dataTransfer: transfer, clientX: target.left + 10, clientY: target.top + 2 };
                row('a').dispatchEvent(new DragEvent('dragstart', { bubbles: true, dataTransfer: transfer }));
                row('c').dispatchEvent(new DragEvent('dragover', at));
                row('c').dispatchEvent(new DragEvent('drop', at));
                row('a').dispatchEvent(new DragEvent('dragend', { bubbles: true, dataTransfer: transfer }));
            JS);
            $browser->waitUntil(self::EDITOR.'.layers.map(e => e.id).join() === "a,c,b"', 5);

            // Double-click a name, type, Enter.
            $browser->script('document.querySelector(\'[dusk="layer-name-b"]\').dispatchEvent(new MouseEvent("dblclick", { bubbles: true }));');
            $browser->waitFor('@layer-rename-b');
            $browser->script(<<<'JS'
                const input = document.querySelector('[dusk="layer-rename-b"]');
                input.value = 'Hero banner';
                input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
            JS);
            $browser->waitUntilMissing('@layer-rename-b', 5)->assertSeeIn('@layer-b', 'Hero banner');

            $this->saveAndWait($browser);

            $saved = collect($ad->fresh()->document['elements'])->keyBy('id');
            $this->assertSame(2, (int) $saved['a']['z']);
            $this->assertSame('Hero banner', $saved['b']['name']);
        });
    }

    public function test_guides_come_out_of_the_rulers_and_things_snap_to_them(): void
    {
        [$designer, $store, $ad] = $this->adWith([$this->shape('box', 100, 100, 200, 100)]);

        $this->browse(function (Browser $browser) use ($designer, $store, $ad) {
            $this->openEditor($browser, $designer, $store, $ad);
            $browser->waitFor('@ruler-top');

            // Out of the top ruler, down to 400: a guide across the stage.
            $browser->script(<<<'JS'
                const editor = Alpine.$data(document.querySelector('[x-data^="adEditor"]'));
                const ruler = document.querySelector('[dusk="ruler-top"]').getBoundingClientRect();
                const stage = document.querySelector('[dusk="ad-stage"]').getBoundingClientRect();
                const to = { bubbles: true, pointerId: 1, button: 0, clientX: stage.left + 300 * editor.zoom, clientY: stage.top + 400 * editor.zoom };
                document.querySelector('[dusk="ruler-top"]').dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, pointerId: 1, button: 0, clientX: ruler.left + 40, clientY: ruler.top + 5 }));
                window.dispatchEvent(new PointerEvent('pointermove', to));
                window.dispatchEvent(new PointerEvent('pointerup', to));
            JS);
            $browser->waitFor('@guide-y-0');
            $this->assertSame([400], $browser->script('return '.self::EDITOR.'.doc.guides.y;')[0]);

            // Dragged to within a few pixels of it, the box's top edge snaps onto the guide.
            $this->drag($browser, '@element-box', 0, 294);
            $this->assertSame(400, (int) $this->element($browser, 'box')['y']);

            // Shift+R hides the rulers and the guides; again brings them back.
            $this->key($browser, 'R', ['shiftKey' => true]);
            $browser->waitUntilMissing('@ruler-top', 5)->assertMissing('@guide-y-0');
            $this->key($browser, 'R', ['shiftKey' => true]);
            $browser->waitFor('@guide-y-0');

            $this->saveAndWait($browser);
            $this->assertSame([400], $ad->fresh()->document['guides']['y']);

            // Dragged back onto its ruler, the guide goes.
            $browser->script(<<<'JS'
                const editor = Alpine.$data(document.querySelector('[x-data^="adEditor"]'));
                const guide = document.querySelector('[dusk="guide-y-0"]').getBoundingClientRect();
                const stage = document.querySelector('[dusk="ad-stage"]').getBoundingClientRect();
                const off = { bubbles: true, pointerId: 1, button: 0, clientX: guide.left + 50, clientY: stage.top - 10 };
                document.querySelector('[dusk="guide-y-0"]').dispatchEvent(new PointerEvent('pointerdown', { bubbles: true, pointerId: 1, button: 0, clientX: guide.left + 50, clientY: guide.top + 2 }));
                window.dispatchEvent(new PointerEvent('pointermove', off));
                window.dispatchEvent(new PointerEvent('pointerup', off));
            JS);
            $browser->waitUntilMissing('@guide-y-0', 5);
            $this->assertSame([], $browser->script('return '.self::EDITOR.'.doc.guides.y;')[0]);
        });
    }

    public function test_the_history_panel_autosave_the_shortcuts_zoom_and_pan(): void
    {
        [$designer, $store, $ad] = $this->adWith([$this->shape('box', 100, 100, 200, 100)]);

        $this->browse(function (Browser $browser) use ($designer, $store, $ad) {
            // Autosave starts ON here because openEditor cleared the setting before the editor read it.
            $this->openEditor($browser, $designer, $store, $ad);
            $this->jsClick($browser, '@layer-box');
            $browser->waitFor('@element-x');

            // Three steps, each with its own name.
            $this->setField($browser, '@element-x', '300');
            $this->setField($browser, '@element-opacity', '0.5');
            $this->setField($browser, '@element-name', 'Moved box');

            $this->jsClick($browser, '@history-toggle');
            $browser->waitFor('@history-panel')
                ->assertSeeIn('@history-panel', 'Position')
                ->assertSeeIn('@history-panel', 'Opacity')
                ->assertSeeIn('@history-panel', 'Rename');

            // Back to the step after the move: moved, but not yet faded or renamed.
            $this->jsClick($browser, '@history-step-1');
            $box = $this->element($browser, 'box');
            $this->assertSame([300, 1, 'Box'], [(int) $box['x'], (int) $box['opacity'], $box['name']]);

            // Autosave: a saved ad with changes is saved on its own, and the bar says so.
            $this->assertTrue($browser->script('return '.self::EDITOR.'.autosaveTick();')[0]);
            $browser->waitUsing(10, 200, fn () => (int) ($ad->fresh()->document['elements'][0]['x'] ?? 0) === 300);
            $browser->waitUntil('document.querySelector(\'[dusk="save-status"]\').textContent.includes("Autosaved")', 5);

            // …and not once it is switched off.
            $this->jsClick($browser, '@autosave-toggle');
            $this->setField($browser, '@element-x', '444');
            $this->assertFalse($browser->script('return '.self::EDITOR.'.autosaveTick();')[0]);
            $this->assertSame('Unsaved changes', trim($browser->text('@save-status')));

            // The shortcuts list opens with ? and closes with Esc.
            $this->key($browser, '?', ['shiftKey' => true]);
            $browser->waitFor('@shortcuts-modal')->assertSeeIn('@shortcuts-modal', 'Paste the copied element');
            $this->key($browser, 'Escape');
            $browser->waitUntilMissing('@shortcuts-modal', 5);

            // Zoom: Shift+0 is 100 %, Ctrl+0 fits again; Ctrl+wheel zooms, the wheel pans.
            $this->key($browser, ')', ['shiftKey' => true, 'code' => 'Digit0']);
            $this->assertSame(100, (int) $browser->script('return '.self::EDITOR.'.zoomPercent;')[0]);
            $this->key($browser, '0', ['ctrlKey' => true]);
            $fitted = (int) $browser->script('return '.self::EDITOR.'.zoomPercent;')[0];
            $this->assertLessThan(100, $fitted);

            $browser->script('document.querySelector(\'[x-ref="viewport"]\').dispatchEvent(new WheelEvent("wheel", { bubbles: true, cancelable: true, deltaY: -100, ctrlKey: true }));');
            $this->assertGreaterThan($fitted, (int) $browser->script('return '.self::EDITOR.'.zoomPercent;')[0]);

            $browser->script('document.querySelector(\'[x-ref="viewport"]\').dispatchEvent(new WheelEvent("wheel", { bubbles: true, cancelable: true, deltaY: 120 }));');
            $this->assertSame(-120, (int) $browser->script('return '.self::EDITOR.'.panY;')[0]);

            // Ctrl+P plays the whole ad and stops it again.
            $this->key($browser, 'p', ['ctrlKey' => true]);
            $browser->waitFor('@preview-overlay');
            $this->key($browser, 'p', ['ctrlKey' => true]);
            $browser->waitUntilMissing('@preview-overlay', 5);
        });
    }

    public function test_a_poster_is_taken_on_save_and_the_draft_preview_opens_sandboxed(): void
    {
        $this->seedSuperAdmin();
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $designer = $this->storeMember($store, ['ad-view', 'ad-store', 'ad-update'], 'designer@example.com', 'Designer');

        $this->browse(function (Browser $browser) use ($designer, $store) {
            $this->freshSession($browser);
            $browser->loginAs($designer);
            $this->switchToStore($browser, $store);

            $browser->visit('/builder/create?orientation=landscape');
            $this->waitForAlpine($browser);
            $browser->waitFor('@stage-empty-hint');

            $this->jsClick($browser, '@add-shape');
            $browser->waitUntilMissing('@stage-empty-hint', 5);
            $this->jsType($browser, '@ad-name', 'Postered');
            $this->jsClick($browser, '@ad-save');

            $browser->waitUsing(30, 250, fn () => BuilderAd::where('name', 'Postered')->whereNotNull('thumbnail_path')->exists());
            $ad = BuilderAd::firstWhere('name', 'Postered');

            // A real JPEG, a third of the stage, drawn by the server from what the browser sent.
            $size = getimagesizefromstring(Storage::disk('public')->get($ad->thumbnail_path));
            $this->assertSame([640, 360, IMAGETYPE_JPEG], [$size[0], $size[1], $size[2]]);

            // …and a picture OF the design: the stage's colour in a corner, the shape's blue at its middle.
            // A copy the browser could not parse once came out as a blank rectangle of exactly that size.
            $poster = imagecreatefromstring(Storage::disk('public')->get($ad->thumbnail_path));
            $colour = function (int $x, int $y) use ($poster): array {
                $rgb = imagecolorat($poster, $x, $y);

                return [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
            };
            $shape = $ad->document['elements'][0];

            $this->assertEqualsWithDelta([15, 23, 42], $colour(8, 8), 16, 'the stage colour');
            $this->assertEqualsWithDelta([37, 99, 235], $colour(
                intdiv((int) $shape['x'] + intdiv((int) $shape['w'], 2), 3),
                intdiv((int) $shape['y'] + intdiv((int) $shape['h'], 2), 3),
            ), 24, 'the shape');

            // The draft preview: a tab of its own, the saved design, in an opaque origin.
            $before = $browser->driver->getWindowHandles();
            $this->jsClick($browser, '@ad-preview');
            $browser->waitUsing(10, 200, fn () => count($browser->driver->getWindowHandles()) > count($before));

            $original = $browser->driver->getWindowHandle();
            $preview = array_values(array_diff($browser->driver->getWindowHandles(), $before))[0];
            $browser->driver->switchTo()->window($preview);
            $browser->waitUntil('document.readyState === "complete" && !!document.getElementById("ad-stage")', 10);

            $sandboxed = $browser->script('try { return document.cookie === undefined ? "none" : "readable"; } catch (e) { return e.name; }')[0];
            $this->assertStringEndsWith('/builder/'.$ad->id.'/preview', $browser->driver->getCurrentURL());
            $this->assertSame('SecurityError', $sandboxed, 'the preview cannot read this app\'s cookies');

            $browser->driver->close();
            $browser->driver->switchTo()->window($original);

            // The listing shows the poster; publishing gives the media library a copy of its own.
            $this->jsClick($browser, '@ad-publish');
            $browser->waitUsing(20, 250, fn () => Media::where('type', Media::TYPE_HTML)->exists());
            $this->assertSame($ad->storageDirectory().'/published.jpg', Media::sole()->thumbnail_path);
            $this->assertSame(Storage::disk('public')->get($ad->fresh()->thumbnail_path), Storage::disk('public')->get(Media::sole()->thumbnail_path));

            $browser->visit('/builder');
            $this->waitForAlpine($browser);
            $browser->waitFor('@ad-poster-'.$ad->id)
                ->waitUntil('document.querySelector(\'[dusk="ad-poster-'.$ad->id.'"]\').naturalWidth === 640', 10)
                ->assertVisible('@preview-ad-'.$ad->id);
        });
    }

    public function test_above_the_stores_the_ads_are_filtered_by_shop(): void
    {
        $admin = $this->seedSuperAdmin();
        $alpha = Store::factory()->create(['name' => 'Alpha Mart']);
        $beta = Store::factory()->create(['name' => 'Beta Deli']);
        $mine = BuilderAd::factory()->withText()->create(['store_id' => $alpha->id, 'name' => 'Alpha sale']);
        $theirs = BuilderAd::factory()->withText()->create(['store_id' => $beta->id, 'name' => 'Beta sale']);

        $this->browse(function (Browser $browser) use ($admin, $beta, $mine, $theirs) {
            $this->freshSession($browser);
            $browser->loginAs($admin);

            $browser->visit('/builder');
            $this->waitForAlpine($browser);
            $browser->waitFor('@ad-card-'.$mine->id)->assertVisible('@ad-card-'.$theirs->id);

            $browser->script('const f = document.querySelector(\'[dusk="ads-filter-store"]\'); f.value = "'.$beta->id.'"; f.dispatchEvent(new Event("change", { bubbles: true }));');
            $browser->waitFor('@ad-card-'.$theirs->id, 10)->waitUntilMissing('@ad-card-'.$mine->id, 5);
        });
    }

    public function test_every_panel_button_does_what_its_shortcut_does(): void
    {
        [$designer, $store, $ad] = $this->adWith([
            [...$this->shape('a', 100, 100, 200, 100, ['fill' => '#ff0000']), 'animations' => ['loop' => [
                'effect' => 'pulse', 'axis' => 'y', 'amount' => 6, 'amountX' => 0, 'amountY' => 0, 'duration' => 1, 'delay' => 0, 'yoyo' => true, 'ease' => 'sine.inOut',
            ]]],
            $this->shape('b', 500, 300, 200, 100),
            $this->shape('c', 1200, 600, 300, 100),
        ]);

        $this->browse(function (Browser $browser) use ($designer, $store, $ad) {
            $this->openEditor($browser, $designer, $store, $ad);
            $z = fn () => (int) $this->element($browser, 'a')['z'];

            // One element: the four order buttons.
            $this->jsClick($browser, '@layer-a');
            $browser->waitFor('@element-front');
            $this->jsClick($browser, '@element-front');
            $this->assertSame(2, $z());
            $this->jsClick($browser, '@element-back');
            $this->assertSame(0, $z());
            $this->jsClick($browser, '@element-forward');
            $this->assertSame(1, $z());
            $this->jsClick($browser, '@element-backward');
            $this->assertSame(0, $z());

            // Several: spacing down, one opacity for all, duplicate and delete.
            $this->key($browser, 'a', ['ctrlKey' => true]);
            $browser->waitFor('@multi-panel');
            $this->jsClick($browser, '@distribute-y');
            $this->assertSame([100, 350, 600], $this->column($browser, 'y'));

            $this->setField($browser, '@multi-opacity', '0.5');
            $this->assertSame(['0.5', '0.5', '0.5'], array_map('strval', $browser->script('return '.self::EDITOR.'.doc.elements.map(e => e.opacity);')[0]));

            $this->jsClick($browser, '@multi-duplicate');
            $browser->waitUntil(self::EDITOR.'.doc.elements.length === 6 && '.self::EDITOR.'.selectedIds.length === 3', 5);
            $this->jsClick($browser, '@multi-delete');
            $browser->waitUntil(self::EDITOR.'.doc.elements.length === 3', 5);

            // Paste style and paste animation from the panel: a's red fill and its pulse, onto all three.
            $this->jsClick($browser, '@layer-a');
            $this->key($browser, 'c', ['ctrlKey' => true]);
            $browser->waitUntil(self::EDITOR.'.clipboardSize === 1', 5);
            $this->key($browser, 'a', ['ctrlKey' => true]);
            $browser->waitFor('@multi-paste-style');
            $this->jsClick($browser, '@multi-paste-style');
            $this->jsClick($browser, '@multi-paste-animation');
            $this->assertSame('#ff0000', $this->element($browser, 'c')['style']['fill']);
            $this->assertSame('pulse', $this->element($browser, 'b')['animations']['loop']['effect']);

            // Hide, and bring back; lock, which also clears the selection.
            $this->jsClick($browser, '@multi-hide');
            $browser->waitUntil(self::EDITOR.'.doc.elements.every(e => e.visible === false)', 5);
            $this->key($browser, 'z', ['ctrlKey' => true]);
            $browser->waitUntil(self::EDITOR.'.doc.elements.every(e => e.visible !== false)', 5);

            $this->key($browser, 'a', ['ctrlKey' => true]);
            $browser->waitFor('@multi-lock');
            $this->jsClick($browser, '@multi-lock');
            $browser->waitUntil(self::EDITOR.'.doc.elements.every(e => e.locked) && '.self::EDITOR.'.selectedIds.length === 0', 5);

            // The view buttons: rulers off and on, the shortcuts list open and shut.
            $this->jsClick($browser, '@rulers-toggle');
            $browser->waitUntilMissing('@ruler-top', 5);
            $this->jsClick($browser, '@rulers-toggle');
            $browser->waitFor('@ruler-top');

            $this->jsClick($browser, '@shortcuts-open');
            $browser->waitFor('@shortcuts-modal');
            $this->jsClick($browser, '@shortcuts-close');
            $browser->waitUntilMissing('@shortcuts-modal', 5);
        });
    }

    /**
     * Esc closes a picker that is open — even from the picker's own search box — and does only that: the
     * element behind it stays selected, rather than one press also letting go of what was being worked on.
     */
    public function test_esc_closes_an_open_picker_and_keeps_the_selection(): void
    {
        [$designer, $store, $ad] = $this->adWith([$this->text('headline', 100, 100, 'Big sale')]);

        $this->browse(function (Browser $browser) use ($designer, $store, $ad) {
            $this->openEditor($browser, $designer, $store, $ad);

            $this->jsClick($browser, '@layer-headline');
            $browser->waitFor('@text-font');

            // The font picker, closed from inside its search box — where every other key belongs to
            // what is being typed.
            $this->clickAndAwait($browser, '@text-font', fn (Browser $b) => $b->waitFor('@font-picker', 3));
            $browser->script(
                'document.querySelector(\'[dusk="font-search"]\').dispatchEvent(new KeyboardEvent("keydown", { key: "Escape", bubbles: true, cancelable: true }));'
            );
            $browser->waitUntilMissing('@font-picker', 5);
            $this->assertSame(['headline'], $browser->script('return '.self::EDITOR.'.selectedIds;')[0],
                'Esc on the font picker let go of the element behind it too');

            // The asset picker, closed with Esc pressed anywhere.
            $this->clickAndAwait($browser, '@add-image', fn (Browser $b) => $b->waitFor('@asset-picker', 3));
            $this->key($browser, 'Escape');
            $browser->waitUntilMissing('@asset-picker', 5);
            $this->assertSame(['headline'], $browser->script('return '.self::EDITOR.'.selectedIds;')[0],
                'Esc on the asset picker let go of the element behind it too');

            // And the panel still edits that element.
            $browser->assertVisible('@element-name');
        });
    }

    /**
     * Ctrl+S pressed while still typing in a field saves what was typed. A field hands its value to the
     * design only when it is left (`change`), so a save that did not let go of the field first would carry
     * the design as it was before the typing — the name on screen, and a different one saved.
     */
    public function test_ctrl_s_while_typing_in_a_field_saves_what_was_typed(): void
    {
        [$designer, $store, $ad] = $this->adWith([$this->shape('box', 100, 100, 200, 100)]);

        $this->browse(function (Browser $browser) use ($designer, $store, $ad) {
            $this->openEditor($browser, $designer, $store, $ad);
            $this->jsClick($browser, '@layer-box');
            $browser->waitFor('@element-name');

            // Typed for real, key by key, so the field holds a change of its own — one that has not
            // reached the design yet.
            $browser->keys('@element-name', ['{control}', 'a'], 'Hero banner');
            $this->assertSame('Hero banner', $browser->value('@element-name'), 'the keystrokes did not all arrive');
            $this->assertSame('Box', $this->element($browser, 'box')['name'], 'the field handed its value over before it was left');

            // Ctrl+S, from inside the field.
            $browser->keys('@element-name', ['{control}', 's']);

            try {
                $browser->waitUsing(30, 250, fn () => ($ad->fresh()->document['elements'][0]['name'] ?? null) === 'Hero banner');
            } catch (TimeoutException) {
                // Leaving a field fires its `change` only in a window that has focus — worth saying
                // alongside what was saved instead.
                $this->fail('The typed name was not saved (saved: '.json_encode($ad->fresh()->document['elements'][0]['name'] ?? null)
                    .'; the page had focus: '.json_encode($browser->script('return document.hasFocus();')[0]).').');
            }

            $browser->waitUntil('!'.self::EDITOR.'.saving && !'.self::EDITOR.'.dirty', 30);
            $this->assertSame('Hero banner', $this->element($browser, 'box')['name']);
        });
    }

    /**
     * Words the Case control shows in capitals are edited as they are STORED, and shown in capitals again
     * afterwards. An inline edit reads back what the element shows, so edited in capitals it would store
     * capitals — rewriting the words even when nobody changed them.
     */
    public function test_words_shown_in_capitals_are_edited_as_typed_and_in_capitals_again_after(): void
    {
        [$designer, $store, $ad] = $this->adWith([
            $this->text('headline', 100, 100, 'Big sale', ['textTransform' => 'uppercase']),
        ]);

        $this->browse(function (Browser $browser) use ($designer, $store, $ad) {
            $this->openEditor($browser, $designer, $store, $ad);

            // The words themselves: inside the element's box, inside what its animations move.
            $words = '[data-anim-id="headline"] .ad-anim > div';
            $look = fn () => $browser->script(
                "const words = document.querySelector('{$words}'); return [getComputedStyle(words).textTransform, words.innerText];"
            )[0];

            $this->assertSame(['uppercase', 'BIG SALE'], $look());

            // A double-click edits them where they stand…
            $browser->script('document.querySelector(\'[dusk="element-headline"]\').dispatchEvent(new MouseEvent("dblclick", { bubbles: true }));');
            $browser->waitUntil('!!document.activeElement && document.activeElement.isContentEditable', 5);

            // …shown as they are stored, while they are being edited.
            $this->assertSame(['none', 'Big sale'], $look());

            // New words over them, and the element let go of, the way a click elsewhere lets go of it.
            $browser->script("const words = document.querySelector('{$words}'); words.textContent = 'Big sale today'; words.blur();");

            try {
                $browser->waitUntil("getComputedStyle(document.querySelector('{$words}')).textTransform === 'uppercase'", 5);
            } catch (TimeoutException) {
                // Letting go is a blur, and a blur fires only in a window that has focus.
                $this->fail('The edit never finished (the page had focus: '.json_encode($browser->script('return document.hasFocus();')[0]).').');
            }

            // Stored exactly as typed, and in capitals on the stage again.
            $this->assertSame('Big sale today', $this->element($browser, 'headline')['text']);
            $this->assertSame(['uppercase', 'BIG SALE TODAY'], $look());

            $this->saveAndWait($browser);
            $this->assertSame('Big sale today', $ad->fresh()->document['elements'][0]['text']);
        });
    }

    /**
     * A press on a guide let go where it began is a click, not a drag: the guide stays where it was and
     * stays on the stage. A click lands anywhere in the guide's hit area — a few pixels either side of the
     * line — and must not move the guide to wherever the pointer happened to be.
     */
    public function test_a_still_click_on_a_guide_leaves_it_where_it_was(): void
    {
        [$designer, $store, $ad] = $this->adWith([$this->shape('box', 100, 100, 200, 100)], ['y' => [400]]);

        $this->browse(function (Browser $browser) use ($designer, $store, $ad) {
            $this->openEditor($browser, $designer, $store, $ad);
            $browser->waitFor('@guide-y-0');

            // Pressed and let go on the same spot, near the top of the hit area — three screen pixels
            // above the line itself, so a guide that followed the pointer would end up off 400.
            $browser->script(<<<'JS'
                const guide = document.querySelector('[dusk="guide-y-0"]');
                const box = guide.getBoundingClientRect();
                const at = { bubbles: true, pointerId: 1, button: 0, clientX: box.left + 50, clientY: box.top + 1 };
                guide.dispatchEvent(new PointerEvent('pointerdown', at));
                window.dispatchEvent(new PointerEvent('pointerup', at));
            JS);

            $browser->assertPresent('@guide-y-0');
            $this->assertSame([400], $browser->script('return '.self::EDITOR.'.doc.guides.y;')[0], 'a click moved the guide');
            $this->assertFalse($browser->script('return '.self::EDITOR.'.dirty;')[0], 'a click on a guide counted as a change');
        });
    }

    /* ── Helpers ─────────────────────────────────────────────────────────── */

    /**
     * @param  array{x?: list<int>, y?: list<int>}  $guides
     * @return array{0: User, 1: Store, 2: BuilderAd}
     */
    private function adWith(array $elements, array $guides = []): array
    {
        $this->seedSuperAdmin();
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $designer = $this->storeMember($store, ['ad-view', 'ad-store', 'ad-update'], 'designer@example.com', 'Designer');

        $document = BuilderAd::blankDocument();
        $document['elements'] = array_map(fn (array $element, int $z) => [...$element, 'z' => $z], $elements, array_keys($elements));

        if ($guides !== []) {
            $document['guides'] = ['x' => [], 'y' => [], ...$guides];
        }

        return [$designer, $store, BuilderAd::factory()->create(['store_id' => $store->id, 'name' => 'Polish', 'document' => $document])];
    }

    private function shape(string $id, int $x, int $y, int $w, int $h, array $style = []): array
    {
        return [
            'id' => $id, 'type' => 'shape', 'name' => ucfirst($id), 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h,
            'rotation' => 0, 'opacity' => 1, 'locked' => false, 'visible' => true,
            'style' => ['shape' => 'rect', 'fill' => '#2563eb', 'radius' => 0, ...$style], 'animations' => [],
        ];
    }

    private function text(string $id, int $x, int $y, string $words, array $style = [], array $animations = []): array
    {
        return [
            'id' => $id, 'type' => 'text', 'name' => ucfirst($id), 'x' => $x, 'y' => $y, 'w' => 700, 'h' => 200,
            'rotation' => 0, 'opacity' => 1, 'locked' => false, 'visible' => true, 'text' => $words,
            'style' => ['fontFamily' => 'Inter', 'fontWeight' => 700, 'align' => 'left', 'lineHeight' => 1.2, ...$style],
            'animations' => $animations,
        ];
    }

    private function openEditor(Browser $browser, User $designer, Store $store, BuilderAd $ad): void
    {
        $this->freshSession($browser);
        $browser->loginAs($designer);
        $this->switchToStore($browser, $store);

        // The editor reads its per-browser settings (autosave, rulers) and its clipboard from
        // localStorage once, as it starts — and Dusk keeps this browser, localStorage and all, from
        // one test of the class to the next. So they are cleared here, on a page of the app's own
        // origin, BEFORE the editor opens: every test starts from the defaults, whatever the last
        // one switched off.
        $browser->script(
            "Object.keys(localStorage).filter((key) => key.startsWith('ad-builder.')).forEach((key) => localStorage.removeItem(key));"
        );

        $browser->visit('/builder/'.$ad->id);
        $this->waitForAlpine($browser);
        $browser->waitFor('@ad-stage');
    }

    /** A key pressed on the window, where the editor listens. Its handler runs there and then. */
    private function key(Browser $browser, string $key, array $options = []): void
    {
        $init = json_encode(['key' => $key, 'bubbles' => true, 'cancelable' => true, ...$options]);

        $browser->script("window.dispatchEvent(new KeyboardEvent('keydown', {$init}));");
    }

    /** Press on an element and drag it by (dx, dy) stage pixels. */
    private function drag(Browser $browser, string $selector, int $dx, int $dy): void
    {
        $css = '[dusk="'.substr($selector, 1).'"]';

        $browser->script(<<<JS
            const editor = Alpine.\$data(document.querySelector('[x-data^="adEditor"]'));
            const node = document.querySelector('{$css}');
            const box = node.getBoundingClientRect();
            const from = { bubbles: true, pointerId: 1, button: 0, clientX: box.left + box.width / 2, clientY: box.top + box.height / 2 };
            const to = { ...from, clientX: from.clientX + {$dx} * editor.zoom, clientY: from.clientY + {$dy} * editor.zoom };
            node.dispatchEvent(new PointerEvent('pointerdown', from));
            window.dispatchEvent(new PointerEvent('pointermove', to));
            window.dispatchEvent(new PointerEvent('pointerup', to));
        JS);
    }

    /** A marquee on empty stage, from one stage point to another. */
    private function marquee(Browser $browser, array $from, array $to): void
    {
        $browser->script(<<<JS
            const editor = Alpine.\$data(document.querySelector('[x-data^="adEditor"]'));
            const stage = document.querySelector('[dusk="ad-stage"]');
            const box = stage.getBoundingClientRect();
            const at = ([x, y]) => ({ bubbles: true, pointerId: 1, button: 0, clientX: box.left + x * editor.zoom, clientY: box.top + y * editor.zoom });
            stage.dispatchEvent(new PointerEvent('pointerdown', at([{$from[0]}, {$from[1]}])));
            window.dispatchEvent(new PointerEvent('pointermove', at([{$to[0]}, {$to[1]}])));
            window.dispatchEvent(new PointerEvent('pointerup', at([{$to[0]}, {$to[1]}])));
        JS);
    }

    private function rightClick(Browser $browser, string $selector): void
    {
        $css = '[dusk="'.substr($selector, 1).'"]';

        $browser->script(<<<JS
            const node = document.querySelector('{$css}');
            const box = node.getBoundingClientRect();
            node.dispatchEvent(new MouseEvent('contextmenu', { bubbles: true, cancelable: true, clientX: box.left + 5, clientY: box.top + 5 }));
        JS);
    }

    /** One element of the document the editor holds, as plain data. */
    private function element(Browser $browser, string $id): array
    {
        return $browser->script('return JSON.parse(JSON.stringify('.self::EDITOR.'.doc.elements.find(e => e.id === "'.$id.'")));')[0];
    }

    /** One number of every element, in id order. */
    private function column(Browser $browser, string $key): array
    {
        return array_map('intval', $browser->script('return [...'.self::EDITOR.'.doc.elements].sort((a, b) => a.id.localeCompare(b.id)).map(e => e.'.$key.');')[0]);
    }

    private function setField(Browser $browser, string $selector, string $value): void
    {
        $css = '[dusk="'.substr($selector, 1).'"]';
        $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], $value);

        $browser->script("const el = document.querySelector('{$css}'); el.value = '{$escaped}'; el.dispatchEvent(new Event('change', { bubbles: true }));");
        $browser->pause(700);       // past the history's coalescing window, so each change is its own step
    }

    /**
     * Save, and wait for the server to have it. The click sets `saving` there and then, and `dirty`
     * clears only once the server has answered — so both settled means the database holds what the
     * editor held.
     */
    private function saveAndWait(Browser $browser): void
    {
        $this->jsClick($browser, '@ad-save');
        $browser->waitUntil('!'.self::EDITOR.'.saving && !'.self::EDITOR.'.dirty', 30);
    }
}
