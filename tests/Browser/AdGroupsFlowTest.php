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

            /* ── 4. Turned, every element inside turns about the centroid of what it holds ── */
            $this->undo($browser);
            $this->assertEqualsWithDelta(600, $this->element($browser, 'dish')['w'], 1, 'undo puts the size back');

            $this->rotateBy($browser, 90);

            $turned = $this->element($browser, 'dish');
            $this->assertEqualsWithDelta(90, $turned['rotation'], 1);
            $this->assertEqualsWithDelta(90, $this->element($browser, 'price')['rotation'], 1);
            // The centres are (440, 370), (940, 370) and (340, 470), so the row turns about (573⅓, 403⅓):
            // the dish's centre turns to (606⅔, 270).
            $this->assertEqualsWithDelta(606.67, $turned['x'] + $turned['w'] / 2, 1);
            $this->assertEqualsWithDelta(270, $turned['y'] + $turned['h'] / 2, 1);
            $this->assertSame(0, (int) $this->groupOf($browser, 'dish')['rotation'], 'the group itself has no angle');

            $this->undo($browser);
            $this->assertEqualsWithDelta(0, $this->element($browser, 'dish')['rotation'], 0.01);

            // Turned and turned back by hand — not undone — everything stands where it stood: the centroid is a
            // point the turn leaves where it was. (About the box's centre it would not be: the box around turned
            // children is another box, and turning back about ITS centre lands somewhere else.)
            $before = $this->places($browser, ['dish', 'price', 'note']);
            $this->rotateBy($browser, 30);
            $this->assertEqualsWithDelta(30, $this->element($browser, 'note')['rotation'], 1);
            $this->rotateBy($browser, -30);

            foreach (['dish', 'price', 'note'] as $id) {
                $back = $this->element($browser, $id);

                $this->assertEqualsWithDelta(0, fmod($back['rotation'] + 360, 360) > 180 ? fmod($back['rotation'] + 360, 360) - 360 : fmod($back['rotation'] + 360, 360), 0.01, "{$id} is upright again");
                $this->assertEqualsWithDelta($before[$id][0], $back['x'], 0.6, "{$id} is back where it stood across");
                $this->assertEqualsWithDelta($before[$id][1], $back['y'], 0.6, "{$id} is back where it stood down");
            }

            // (No undo here: two turns made in a moment are one step of the history, as quick steps of one kind
            // are — and they are back where they started anyway.)

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
            $this->assertSame('Group 1 copy', $copy['name']);

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

    public function test_inside_a_group_the_gaps_turned_children_shift_the_layers_and_a_paste_do_what_they_show(): void
    {
        [$designer, $store, $ad] = $this->adWith([
            $this->text('dish', 100, 300, 'Chicken karahi', 600, 100),
            $this->text('price', 800, 300, '$12', 200, 100),
            [...$this->text('bar', 100, 600, 'Rule', 400, 40), 'rotation' => 90],
            $this->text('label', 400, 600, 'Daily', 300, 80),
            $this->text('stray', 1400, 900, 'Loose', 300, 80),
        ]);
        $portrait = BuilderAd::factory()->portrait()->create(['store_id' => $store->id, 'name' => 'Board', 'orientation' => BuilderAd::PORTRAIT]);

        $this->browse(function (Browser $browser) use ($designer, $store, $ad, $portrait) {
            $this->openEditor($browser, $designer, $store, $ad);

            /* ── 1. Two groups: the row (dish, price) and the rule with its label ── */
            $this->selectLayers($browser, ['dish', 'price']);
            $this->jsClick($browser, '@group-selection');
            $browser->waitFor('@group-panel');
            $row = $this->groupOf($browser, 'dish');
            $this->assertSame('Group 1', $row['name'], 'a new group is named with the next free number');

            // A group that neither fades, blends nor moves is no layer of its own on the stage either.
            $this->assertSame('auto', $browser->script('return getComputedStyle(document.querySelector(\'[dusk="element-'.$row['id'].'"]\')).zIndex;')[0]);

            $this->selectLayers($browser, ['bar', 'label']);
            $this->jsClick($browser, '@group-selection');
            $browser->waitFor('@group-panel');
            $rule = $this->groupOf($browser, 'bar');
            $this->assertSame('Group 2', $rule['name']);

            /* ── 2. Inside the row, its own empty middle is empty stage: a drag draws a marquee ── */
            $browser->script('document.querySelector(\'[dusk="element-price"]\').dispatchEvent(new MouseEvent("dblclick", { bubbles: true }));');
            $browser->waitUntil(self::EDITOR.'.editingGroupId === "'.$row['id'].'"', 5);
            $browser->waitFor('@editing-group-frame')->assertSeeIn('@editing-group-label', 'Group 1');

            $this->pressOnStage($browser, '@element-'.$row['id'], 750, 350, 1050, 420);
            $this->assertSame($row['id'], $browser->script('return '.self::EDITOR.'.editingGroupId;')[0], 'still inside the row');
            $this->assertEqualsCanonicalizing(['price'], $browser->script('return '.self::EDITOR.'.selectedIds;')[0], 'the marquee picked the child it crossed');
            $this->assertSame([800, 300], $this->places($browser, ['price'])['price'], 'nothing was dragged');

            // …and a plain click there is a click on empty stage: out of the group, nothing chosen.
            $this->pressOnStage($browser, '@element-'.$row['id'], 750, 350, 750, 350);
            $browser->waitUntil(self::EDITOR.'.editingGroupId === null', 5);
            $this->assertSame([], $browser->script('return '.self::EDITOR.'.selectedIds;')[0]);

            /* ── 3. Widened from a side, a child turned 90° grows thicker — the way it looks — not longer ── */
            $this->jsClick($browser, '@layer-'.$rule['id']);
            $browser->waitFor('@group-panel');
            $box = $this->groupOf($browser, 'bar');
            $this->assertSame([280, 420, 420, 400], [(int) $box['x'], (int) $box['y'], (int) $box['w'], (int) $box['h']]);

            $this->dragHandle($browser, 'e', 210, 0);           // 420 → 630 wide: half as wide again

            $bar = $this->element($browser, 'bar');
            $this->assertEqualsWithDelta(400, $bar['w'], 1, 'the turned bar is as long as it was');
            $this->assertEqualsWithDelta(60, $bar['h'], 1, 'and half as thick again, across the way it points');
            $this->assertEqualsWithDelta(450, $this->element($browser, 'label')['w'], 1, 'the upright label is half as wide again');
            $this->assertEqualsWithDelta(64, $this->element($browser, 'label')['style']['fontSize'], 0.01, 'from a side the type stays its size');
            $this->undo($browser);

            // With Shift held a side still stretches: the type keeps its size (it once scaled with Shift).
            $this->dragHandle($browser, 'e', 210, 0, true);
            $this->assertEqualsWithDelta(64, $this->element($browser, 'label')['style']['fontSize'], 0.01);
            $this->assertEqualsWithDelta(80, $this->element($browser, 'label')['h'], 1);
            $this->undo($browser);

            // A corner keeps the shape and scales the look; with Shift it stretches instead.
            $this->dragHandle($browser, 'se', 210, 0, true);
            $this->assertEqualsWithDelta(64, $this->element($browser, 'label')['style']['fontSize'], 0.01, 'Shift on a corner stretches');
            $this->undo($browser);

            /* ── 4. Layers: just under an open group's row is inside it, in front ── */
            $this->dropRow($browser, 'stray', $row['id'], 0.85);
            $stray = $this->element($browser, 'stray');
            $this->assertSame($row['id'], $stray['parentId'], 'dropped just under the open row, it went inside');
            $this->assertGreaterThan($this->element($browser, 'price')['z'], $stray['z'], 'in front of what was there');
            $this->undo($browser);
            $this->assertArrayNotHasKey('parentId', array_filter($this->element($browser, 'stray'), fn ($value) => $value !== null));

            // Folded, just under its row is beside it — behind it, at its level.
            $this->jsClick($browser, '@layer-fold-'.$row['id']);
            $browser->waitUntilMissing('@layer-dish');
            $this->dropRow($browser, 'stray', $row['id'], 0.85);
            $this->assertArrayNotHasKey('parentId', array_filter($this->element($browser, 'stray'), fn ($value) => $value !== null));
            $this->assertLessThan($this->groupOf($browser, 'dish')['z'], $this->element($browser, 'stray')['z']);
            $this->undo($browser);
            $this->jsClick($browser, '@layer-fold-'.$row['id']);
            $browser->waitFor('@layer-dish');

            /* ── 5. A right-click on a child's row is that child, at its own level ── */
            $browser->script('document.querySelector(\'[dusk="layer-price"]\').dispatchEvent(new MouseEvent("contextmenu", { bubbles: true, cancelable: true, clientX: 200, clientY: 200 }));');
            $browser->waitFor('@context-menu');
            $this->assertSame(['price'], $browser->script('return '.self::EDITOR.'.selectedIds;')[0]);
            $this->assertSame($row['id'], $browser->script('return '.self::EDITOR.'.editingGroupId;')[0]);
            $this->key($browser, 'Escape');

            /* ── 6. Copied from far right of this landscape ad, pasted into a portrait one, it lands on the stage ── */
            $this->key($browser, 'Escape');
            $this->jsClick($browser, '@layer-stray');
            $this->key($browser, 'c', ['ctrlKey' => true]);

            $browser->visit('/builder/'.$portrait->id);
            $this->waitForAlpine($browser);
            $browser->waitFor('@ad-stage');
            $this->key($browser, 'v', ['ctrlKey' => true]);
            $browser->waitUntil(self::EDITOR.'.doc.elements.length === 1', 5);

            $pasted = $browser->script('return JSON.parse(JSON.stringify('.self::EDITOR.'.doc.elements[0]));')[0];
            $this->assertGreaterThanOrEqual(0, $pasted['x']);
            $this->assertLessThanOrEqual(1080, $pasted['x'] + $pasted['w'], 'the paste is on the 1080-wide stage, not off its edge');
            $this->assertSame(932, (int) $pasted['y'], 'what already fitted down the stage stays where it was (900 + the paste offset)');
        });
    }

    /* ── Helpers ─────────────────────────────────────────────────────── */

    /**
     * A press on $selector at stage point (x, y), moved to (toX, toY) and let go — the pointer events the stage
     * listens for. Where both points are the same, a plain click.
     */
    private function pressOnStage(Browser $browser, string $selector, int $x, int $y, int $toX, int $toY): void
    {
        $css = '[dusk="'.substr($selector, 1).'"]';

        $browser->script(<<<JS
            const editor = {$this->editor()};
            const stage = document.querySelector('[dusk="ad-stage"]').getBoundingClientRect();
            const at = (sx, sy) => ({ clientX: stage.left + sx * editor.zoom, clientY: stage.top + sy * editor.zoom });
            const from = { bubbles: true, pointerId: 1, button: 0, ...at({$x}, {$y}) };
            const to = { ...from, ...at({$toX}, {$toY}) };
            document.querySelector('{$css}').dispatchEvent(new PointerEvent('pointerdown', from));
            if ({$x} !== {$toX} || {$y} !== {$toY}) window.dispatchEvent(new PointerEvent('pointermove', to));
            window.dispatchEvent(new PointerEvent('pointerup', to));
        JS);
    }

    /** The Layers row of $id dragged onto $targetId's row, let go at $at of its height (0 top, 1 bottom). */
    private function dropRow(Browser $browser, string $id, string $targetId, float $at): void
    {
        $browser->script(<<<JS
            const row = document.querySelector('[dusk="layer-{$id}"]');
            const target = document.querySelector('[dusk="layer-{$targetId}"]');
            const box = target.getBoundingClientRect();
            const transfer = new DataTransfer();
            const point = { bubbles: true, cancelable: true, dataTransfer: transfer, clientX: box.left + 20, clientY: box.top + box.height * {$at} };
            row.dispatchEvent(new DragEvent('dragstart', { bubbles: true, dataTransfer: transfer }));
            target.dispatchEvent(new DragEvent('dragover', point));
            target.dispatchEvent(new DragEvent('drop', point));
            row.dispatchEvent(new DragEvent('dragend', { bubbles: true, dataTransfer: transfer }));
        JS);
    }

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

    /** Drag one of the selection's resize handles by (dx, dy) stage pixels — with Shift held, when asked. */
    private function dragHandle(Browser $browser, string $handle, int $dx, int $dy, bool $shift = false): void
    {
        $shiftKey = $shift ? 'true' : 'false';

        $browser->waitFor('@handle-'.$handle);
        $browser->script(<<<JS
            const editor = {$this->editor()};
            const node = document.querySelector('[dusk="handle-{$handle}"]');
            const box = node.getBoundingClientRect();
            const from = { bubbles: true, pointerId: 1, button: 0, clientX: box.left + box.width / 2, clientY: box.top + box.height / 2, shiftKey: {$shiftKey} };
            const to = { ...from, clientX: from.clientX + {$dx} * editor.zoom, clientY: from.clientY + {$dy} * editor.zoom };
            node.dispatchEvent(new PointerEvent('pointerdown', from));
            window.dispatchEvent(new PointerEvent('pointermove', to));
            window.dispatchEvent(new PointerEvent('pointerup', to));
        JS);
    }

    /**
     * Drag the rotate handle so the selection turns by about `degrees` (clockwise), then let go — the pointer
     * travelling round the point the editor turns it about: an element's centre, a group's centroid (§13).
     */
    private function rotateBy(Browser $browser, int $degrees): void
    {
        $browser->waitFor('@handle-rotate');
        $browser->script(<<<JS
            const editor = {$this->editor()};
            const handle = document.querySelector('[dusk="handle-rotate"]');
            const selected = editor.selected;
            const pivot = selected.type === 'group'
                ? editor.pivotOf(editor.subtreeStart(selected))
                : { x: selected.x + selected.w / 2, y: selected.y + selected.h / 2 };
            const stage = document.querySelector('[dusk="ad-stage"]').getBoundingClientRect();
            const centre = { x: stage.left + pivot.x * editor.zoom, y: stage.top + pivot.y * editor.zoom };
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
