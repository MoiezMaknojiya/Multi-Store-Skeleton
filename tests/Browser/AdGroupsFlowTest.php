<?php

namespace Tests\Browser;

use App\Models\BuilderAd;
use App\Models\Media;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Storage;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Groups through the real editor (docs/AD-BUILDER-SPEC.md §13): three texts made one, the folder in the
 * Layers panel, the group dragged (its children follow), resized from a corner (children and their type
 * scale with it), turned (children turn about its centre), entered with a double-click and a child moved
 * on its own, left with Esc, duplicated, ungrouped — and a group animation on the published page's wrapper.
 *
 * Every gesture is a real pointer or keyboard event dispatched in the page, and every result is read from
 * the document the editor holds or, once saved, from the database.
 */
class AdGroupsFlowTest extends DuskTestCase
{
    use DatabaseMigrations;

    /** The editor's component, from inside the page. */
    private const EDITOR = 'Alpine.$data(document.querySelector(\'[x-data^="adEditor"]\'))';

    public function test_a_menu_row_is_grouped_moved_scaled_turned_edited_inside_and_ungrouped(): void
    {
        [$designer, $store, $ad] = $this->adWith([
            $this->text('dish', 100, 300, 'Chicken karahi', 600, 100),
            $this->text('price', 800, 300, '$12', 200, 100),
            $this->text('note', 100, 420, 'with naan', 400, 60),
            $this->text('headline', 100, 60, 'Menu of the day', 900, 120),
        ]);

        $this->browse(function (Browser $browser) use ($designer, $store, $ad) {
            $this->openEditor($browser, $designer, $store, $ad);

            /* ── 1. Three texts become one group, placed where the frontmost of them was ── */
            $this->jsClick($browser, '@layer-dish');
            $browser->script('document.querySelector(\'[dusk="layer-price"]\').dispatchEvent(new MouseEvent("click", { bubbles: true, shiftKey: true }));');
            $browser->script('document.querySelector(\'[dusk="layer-note"]\').dispatchEvent(new MouseEvent("click", { bubbles: true, shiftKey: true }));');
            $browser->waitFor('@multi-panel')->assertSeeIn('@multi-count', '3');

            $this->jsClick($browser, '@group-selection');
            $browser->waitFor('@group-panel')->assertSeeIn('@group-count', '3');

            $group = $this->groupOf($browser, 'dish');
            $this->assertSame('group', $group['type']);
            $this->assertSame($group['id'], $this->element($browser, 'price')['parentId']);
            $this->assertSame($group['id'], $this->element($browser, 'note')['parentId']);
            $this->assertArrayNotHasKey('parentId', array_filter($this->element($browser, 'headline'), fn ($value) => $value !== null));
            // Its box is the box around the three.
            $this->assertSame([100, 300, 900, 180], [(int) $group['x'], (int) $group['y'], (int) $group['w'], (int) $group['h']]);

            // The Layers panel shows a folder with the three indented under it, and it folds.
            $browser->assertVisible('@layer-fold-'.$group['id'])
                ->assertVisible('@layer-dish');
            $this->assertGreaterThan(
                (int) $browser->script('return parseInt(getComputedStyle(document.querySelector(\'[dusk="layer-'.$group['id'].'"]\')).paddingLeft);')[0],
                (int) $browser->script('return parseInt(getComputedStyle(document.querySelector(\'[dusk="layer-dish"]\')).paddingLeft);')[0],
                'a child is indented under its group'
            );
            $this->jsClick($browser, '@layer-fold-'.$group['id']);
            $browser->waitUntilMissing('@layer-dish');
            $this->jsClick($browser, '@layer-fold-'.$group['id']);
            $browser->waitFor('@layer-dish');

            /* ── 2. Dragged, the whole row follows; a click on a child selects the group ── */
            $this->drag($browser, '@element-price', 40, 20);
            $this->assertSame(['dish' => [140, 320], 'price' => [840, 320], 'note' => [140, 440]], $this->places($browser, ['dish', 'price', 'note']));
            $this->assertSame([100, 60], $this->places($browser, ['headline'])['headline'], 'what is outside the group stays put');
            $this->assertSame([$group['id']], $browser->script('return '.self::EDITOR.'.selectedIds;')[0]);

            /* ── 3. Resized from a corner, what is inside scales with it — type included ── */
            $this->dragHandle($browser, 'se', 450, 90);          // the row's box 900×180 → 1350×270, its shape kept

            $scaled = $this->element($browser, 'dish');
            $this->assertEqualsWithDelta(900, $scaled['w'], 1, 'the dish is half as wide again');
            $this->assertEqualsWithDelta(150, $scaled['h'], 1);
            $this->assertEqualsWithDelta(96, $scaled['style']['fontSize'], 1, 'its type scales with the group');
            $priceScaled = $this->element($browser, 'price');
            $this->assertEqualsWithDelta(140 + 700 * 1.5, $priceScaled['x'], 1, 'the price keeps its place in the row');

            /* ── 4. Turned, every element inside turns about the row's centre ── */
            $this->undo($browser);
            $this->assertEqualsWithDelta(600, $this->element($browser, 'dish')['w'], 1, 'undo puts the size back');

            $this->rotateBy($browser, 90);

            $turned = $this->element($browser, 'dish');
            $this->assertEqualsWithDelta(90, $turned['rotation'], 1);
            $this->assertEqualsWithDelta(90, $this->element($browser, 'price')['rotation'], 1);
            // The row's centre was (590, 410): the dish's centre (440, 370) turns to (630, 260).
            $this->assertEqualsWithDelta(630, $turned['x'] + $turned['w'] / 2, 1);
            $this->assertEqualsWithDelta(260, $turned['y'] + $turned['h'] / 2, 1);
            $this->assertSame(0, (int) $this->groupOf($browser, 'dish')['rotation'], 'the group itself has no angle');

            $this->undo($browser);
            $this->assertEqualsWithDelta(0, $this->element($browser, 'dish')['rotation'], 0.01);

            /* ── 5. Double-click goes inside: a child is selected and moved on its own; Esc steps out ── */
            $browser->script('document.querySelector(\'[dusk="element-price"]\').dispatchEvent(new MouseEvent("dblclick", { bubbles: true }));');
            $browser->waitUntil(self::EDITOR.'.editingGroupId === "'.$group['id'].'"', 5);
            $this->assertSame(['price'], $browser->script('return '.self::EDITOR.'.selectedIds;')[0]);

            $this->drag($browser, '@element-price', 0, 100);
            $this->assertSame([840, 420], $this->places($browser, ['price'])['price']);
            $this->assertSame([140, 320], $this->places($browser, ['dish'])['dish'], 'the others stay where they were');
            $this->assertEqualsWithDelta(200, $this->groupOf($browser, 'dish')['h'], 1, 'the group grew with the price');

            $this->key($browser, 'Escape');
            $browser->waitUntil(self::EDITOR.'.editingGroupId === null', 5);
            $this->assertSame([$group['id']], $browser->script('return '.self::EDITOR.'.selectedIds;')[0]);

            /* ── 6. Duplicated with everything inside; ungrouped, its children come free ── */
            $this->key($browser, 'd', ['ctrlKey' => true]);
            $browser->waitUntil(self::EDITOR.'.doc.elements.filter((e) => e.type === "group").length === 2', 5);
            $copy = $browser->script('return JSON.parse(JSON.stringify('.self::EDITOR.'.doc.elements.find((e) => e.type === "group" && e.id !== "'.$group['id'].'")));')[0];
            $this->assertSame(3, (int) $browser->script('return '.self::EDITOR.'.doc.elements.filter((e) => e.parentId === "'.$copy['id'].'").length;')[0]);
            $this->assertSame('Group copy', $copy['name']);

            $this->key($browser, 'Delete');
            $browser->waitUntil(self::EDITOR.'.doc.elements.filter((e) => e.type === "group").length === 1', 5);
            $this->assertSame(5, (int) $browser->script('return '.self::EDITOR.'.doc.elements.length;')[0], 'the copy went with everything inside it');

            $this->jsClick($browser, '@layer-'.$group['id']);
            $browser->waitFor('@group-panel');
            $this->jsClick($browser, '@ungroup');
            $browser->waitUntil(self::EDITOR.'.doc.elements.filter((e) => e.type === "group").length === 0', 5);
            $this->assertNull($this->element($browser, 'dish')['parentId'] ?? null);
            $this->assertSame(3, count($browser->script('return '.self::EDITOR.'.selectedIds;')[0]), 'the freed children are selected');

            /* ── 7. Grouped again with an entrance of its own, saved and published: the page has the wrapper ── */
            $this->key($browser, 'g', ['ctrlKey' => true]);
            $browser->waitFor('@group-panel');
            $regrouped = $this->groupOf($browser, 'dish');

            $this->jsClick($browser, '@panel-tab-animation');
            $browser->waitFor('@animation-panel');
            $browser->script(self::EDITOR.'.setEffect("in", "slide");');
            $browser->waitUntil('!!'.self::EDITOR.'.doc.elements.find((e) => e.id === "'.$regrouped['id'].'").animations.in', 5);

            $this->jsClick($browser, '@ad-save');
            $browser->waitUntil('!'.self::EDITOR.'.saving && !'.self::EDITOR.'.dirty', 30);

            $saved = collect($ad->fresh()->document['elements'])->keyBy('id');
            $this->assertSame('group', $saved[$regrouped['id']]['type']);
            $this->assertSame($regrouped['id'], $saved['dish']['parentId']);
            $this->assertSame('slide', $saved[$regrouped['id']]['animations']['in']['effect']);

            $this->jsClick($browser, '@ad-publish');
            $browser->waitUsing(25, 250, fn () => Media::where('store_id', $store->id)->where('type', Media::TYPE_HTML)->exists());
            $html = Storage::disk('public')->get(Media::where('type', Media::TYPE_HTML)->sole()->path);

            $this->assertStringContainsString('data-anim-id="'.$regrouped['id'].'"', $html);
            $this->assertMatchesRegularExpression('/data-anim-id="'.$regrouped['id'].'"[^>]*><div class="ad-anim">\s*<div class="ad-el" data-anim-id="dish"/', $html);
            $this->assertStringContainsString('"'.$regrouped['id'].'":{"in":{"effect":"slide"', $html);

            $browser->screenshot('group-in-editor');
        });
    }

    public function test_groups_nest_three_deep_and_no_deeper(): void
    {
        [$designer, $store, $ad] = $this->adWith([
            $this->text('a', 100, 100, 'a', 200, 60),
            $this->text('b', 400, 100, 'b', 200, 60),
            $this->text('c', 700, 100, 'c', 200, 60),
            $this->text('d', 1000, 100, 'd', 200, 60),
        ]);

        $this->browse(function (Browser $browser) use ($designer, $store, $ad) {
            $this->openEditor($browser, $designer, $store, $ad);

            // a+b → group 1; group 1 + c → group 2; group 2 + d → group 3: three deep for a and b.
            $this->selectLayers($browser, ['a', 'b']);
            $this->key($browser, 'g', ['ctrlKey' => true]);
            $browser->waitFor('@group-panel');
            $g1 = $this->groupOf($browser, 'a')['id'];

            $this->selectLayers($browser, [$g1, 'c']);
            $this->key($browser, 'g', ['ctrlKey' => true]);
            $browser->waitUntil(self::EDITOR.'.doc.elements.filter((e) => e.type === "group").length === 2', 5);
            $g2 = $this->element($browser, $g1)['parentId'];

            $this->selectLayers($browser, [$g2, 'd']);
            $this->key($browser, 'g', ['ctrlKey' => true]);
            $browser->waitUntil(self::EDITOR.'.doc.elements.filter((e) => e.type === "group").length === 3', 5);
            $g3 = $this->element($browser, $g2)['parentId'];
            $this->assertNotNull($g3);

            // A fourth level is refused, and said so.
            $this->selectLayers($browser, [$g3]);
            $browser->script('document.querySelector(\'[dusk="layer-headline"]\')');
            $this->jsClick($browser, '@layer-'.$g3);
            $this->key($browser, 'd', ['ctrlKey' => true]);       // a copy of the deepest tree, to group with the original
            $browser->waitUntil(self::EDITOR.'.doc.elements.filter((e) => e.type === "group").length === 6', 5);
            $this->key($browser, 'a', ['ctrlKey' => true]);
            $this->key($browser, 'g', ['ctrlKey' => true]);
            $browser->waitForText('Groups can be 3 deep at most.');
            $this->assertSame(6, (int) $browser->script('return '.self::EDITOR.'.doc.elements.filter((e) => e.type === "group").length;')[0]);

            // The design saves with the tree intact — the server's own rules accept three deep.
            $this->jsClick($browser, '@ad-save');
            $browser->waitUntil('!'.self::EDITOR.'.saving && !'.self::EDITOR.'.dirty', 30);
            $this->assertSame($g1, collect($ad->fresh()->document['elements'])->keyBy('id')['a']['parentId']);
        });
    }

    /* ── Helpers ─────────────────────────────────────────────────────── */

    private function adWith(array $elements): array
    {
        $this->seedSuperAdmin();
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $designer = $this->storeMember($store, ['ad-view', 'ad-store', 'ad-update'], 'designer@example.com', 'Designer');

        $document = BuilderAd::blankDocument();
        $document['elements'] = array_map(fn (array $element, int $z) => [...$element, 'z' => $z], $elements, array_keys($elements));

        return [$designer, $store, BuilderAd::factory()->create(['store_id' => $store->id, 'name' => 'Menu', 'document' => $document])];
    }

    private function text(string $id, int $x, int $y, string $words, int $w, int $h): array
    {
        return [
            'id' => $id, 'type' => 'text', 'name' => ucfirst($id), 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h,
            'rotation' => 0, 'opacity' => 1, 'locked' => false, 'visible' => true, 'text' => $words,
            'style' => ['fontFamily' => 'Inter', 'fontWeight' => 700, 'fontSize' => 64, 'color' => '#ffffff', 'align' => 'left', 'lineHeight' => 1.2],
            'animations' => [],
        ];
    }

    private function openEditor(Browser $browser, User $designer, Store $store, BuilderAd $ad): void
    {
        $this->freshSession($browser);
        $browser->loginAs($designer);
        $this->switchToStore($browser, $store);

        // Every test starts from the editor's defaults, whatever the last one left in this browser.
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

    private function undo(Browser $browser): void
    {
        $this->key($browser, 'z', ['ctrlKey' => true]);
    }

    /** Rows of the Layers panel clicked: the first plainly, the rest with Shift. */
    private function selectLayers(Browser $browser, array $ids): void
    {
        foreach ($ids as $index => $id) {
            $shift = $index === 0 ? 'false' : 'true';

            $browser->script('document.querySelector(\'[dusk="layer-'.$id.'"]\').dispatchEvent(new MouseEvent("click", { bubbles: true, shiftKey: '.$shift.' }));');
        }
    }

    /** Press on an element and drag it by (dx, dy) stage pixels. */
    private function drag(Browser $browser, string $selector, int $dx, int $dy): void
    {
        $css = '[dusk="'.substr($selector, 1).'"]';

        $browser->script(<<<JS
            const editor = {$this->editor()};
            const node = document.querySelector('{$css}');
            const box = node.getBoundingClientRect();
            const from = { bubbles: true, pointerId: 1, button: 0, clientX: box.left + box.width / 2, clientY: box.top + box.height / 2 };
            const to = { ...from, clientX: from.clientX + {$dx} * editor.zoom, clientY: from.clientY + {$dy} * editor.zoom };
            node.dispatchEvent(new PointerEvent('pointerdown', from));
            window.dispatchEvent(new PointerEvent('pointermove', to));
            window.dispatchEvent(new PointerEvent('pointerup', to));
        JS);
    }

    /** Drag one of the selection's resize handles by (dx, dy) stage pixels. */
    private function dragHandle(Browser $browser, string $handle, int $dx, int $dy): void
    {
        $browser->waitFor('@handle-'.$handle);
        $browser->script(<<<JS
            const editor = {$this->editor()};
            const node = document.querySelector('[dusk="handle-{$handle}"]');
            const box = node.getBoundingClientRect();
            const from = { bubbles: true, pointerId: 1, button: 0, clientX: box.left + box.width / 2, clientY: box.top + box.height / 2 };
            const to = { ...from, clientX: from.clientX + {$dx} * editor.zoom, clientY: from.clientY + {$dy} * editor.zoom };
            node.dispatchEvent(new PointerEvent('pointerdown', from));
            window.dispatchEvent(new PointerEvent('pointermove', to));
            window.dispatchEvent(new PointerEvent('pointerup', to));
        JS);
    }

    /** Drag the rotate handle so the selection turns by about `degrees` (clockwise), then let go. */
    private function rotateBy(Browser $browser, int $degrees): void
    {
        $browser->waitFor('@handle-rotate');
        $browser->script(<<<JS
            const editor = {$this->editor()};
            const handle = document.querySelector('[dusk="handle-rotate"]');
            const frame = document.querySelector('[dusk="selection-frame"]').getBoundingClientRect();
            const centre = { x: frame.left + frame.width / 2, y: frame.top + frame.height / 2 };
            const box = handle.getBoundingClientRect();
            const from = { bubbles: true, pointerId: 1, button: 0, clientX: box.left + box.width / 2, clientY: box.top + box.height / 2 };
            const radius = Math.hypot(from.clientX - centre.x, from.clientY - centre.y);
            const start = Math.atan2(from.clientX - centre.x, -(from.clientY - centre.y));
            const angle = start + {$degrees} * Math.PI / 180;
            const to = { ...from, clientX: centre.x + Math.sin(angle) * radius, clientY: centre.y - Math.cos(angle) * radius };
            handle.dispatchEvent(new PointerEvent('pointerdown', from));
            window.dispatchEvent(new PointerEvent('pointermove', to));
            window.dispatchEvent(new PointerEvent('pointerup', to));
        JS);
    }

    private function editor(): string
    {
        return 'Alpine.$data(document.querySelector(\'[x-data^="adEditor"]\'))';
    }

    /** One element of the document the editor holds, as plain data. */
    private function element(Browser $browser, string $id): array
    {
        return $browser->script('return JSON.parse(JSON.stringify('.self::EDITOR.'.doc.elements.find(e => e.id === "'.$id.'")));')[0];
    }

    /** The group an element is inside, as plain data. */
    private function groupOf(Browser $browser, string $id): array
    {
        return $browser->script('const doc = '.self::EDITOR.'.doc; const el = doc.elements.find(e => e.id === "'.$id.'"); return JSON.parse(JSON.stringify(doc.elements.find(e => e.id === el.parentId)));')[0];
    }

    /** Where each element stands, rounded: id → [x, y]. */
    private function places(Browser $browser, array $ids): array
    {
        $places = [];

        foreach ($ids as $id) {
            $element = $this->element($browser, $id);
            $places[$id] = [(int) round($element['x']), (int) round($element['y'])];
        }

        return $places;
    }
}
