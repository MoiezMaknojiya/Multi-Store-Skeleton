<?php

namespace Tests\Browser;

use App\Models\BuilderAd;
use App\Models\Media;
use App\Models\Role;
use App\Models\Store;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Storage;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * A line through the real editor (docs/AD-BUILDER-SPEC.md §14): added from the panel, made thicker and
 * dashed and coloured, turned, saved and published — the stage draws the stroke the way the page will.
 */
class AdLineFlowTest extends DuskTestCase
{
    use DatabaseMigrations;

    private const EDITOR = 'Alpine.$data(document.querySelector(\'[x-data^="adEditor"]\'))';

    public function test_a_line_is_added_styled_turned_and_published(): void
    {
        $this->seedSuperAdmin();
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $owner = $this->storeMember($store, Role::OWNER, 'owner@example.com');
        $ad = BuilderAd::factory()->create(['store_id' => $store->id, 'name' => 'Menu']);

        $this->browse(function (Browser $browser) use ($owner, $store, $ad) {
            $this->freshSession($browser);
            $browser->loginAs($owner);
            $this->switchToStore($browser, $store);

            $browser->visit('/builder/'.$ad->id);
            $this->waitForAlpine($browser);
            $browser->waitFor('@ad-stage');

            /* ── 1. A line arrives: six pixels, solid, white, across a 600 × 40 box ── */
            $this->jsClick($browser, '@add-line');
            $browser->waitFor('@line-controls')
                ->assertMissing('@shape-fill-gradient')
                ->assertMissing('@shape-radius');

            $line = $this->element($browser);
            $this->assertSame(['shape', 'line', 600, 40], [$line['type'], $line['style']['shape'], (int) $line['w'], (int) $line['h']]);
            $this->assertSame('6', $browser->value('@line-width'));

            $stroke = $browser->script('return getComputedStyle(document.querySelector(\'[dusk="element-'.$line['id'].'"] .ad-anim > div\')).borderTop;')[0];
            $this->assertSame('6px solid rgb(255, 255, 255)', $stroke);

            /* ── 2. Thicker, dashed, gold — and past the limit it is held, visibly ── */
            $this->setValue($browser, '@line-width', '12', 'change');
            $this->setValue($browser, '@line-style', 'dashed', 'change');
            $this->setValue($browser, '@line-color', '#ffd166', 'change');
            $this->setValue($browser, '@element-rotation', '-4', 'change');

            $browser->waitUntil(self::EDITOR.'.doc.elements[0].style.lineStyle === "dashed"', 5);
            $stroke = $browser->script('return getComputedStyle(document.querySelector(\'[dusk="element-'.$line['id'].'"] .ad-anim > div\')).borderTop;')[0];
            $this->assertSame('12px dashed rgb(255, 209, 102)', $stroke);

            $this->setValue($browser, '@line-width', '9999', 'change');
            $browser->waitUntil(self::EDITOR.'.doc.elements[0].style.lineWidth === 200', 5);
            $this->assertSame('200', $browser->value('@line-width'));
            $this->setValue($browser, '@line-width', '12', 'change');

            /* ── 3. Saved and published: the page carries the stroke ── */
            $this->jsClick($browser, '@ad-save');
            $browser->waitUntil('!'.self::EDITOR.'.saving && !'.self::EDITOR.'.dirty', 30);

            $saved = $ad->fresh()->document['elements'][0];
            $this->assertSame(['line', 12, 'dashed', '#ffd166', -4], [
                $saved['style']['shape'], (int) $saved['style']['lineWidth'], $saved['style']['lineStyle'], $saved['style']['fill'], (int) $saved['rotation'],
            ]);

            $this->jsClick($browser, '@ad-publish');
            $browser->waitUsing(25, 250, fn () => Media::where('store_id', $store->id)->where('type', Media::TYPE_HTML)->exists());
            $html = Storage::disk('public')->get(Media::where('type', Media::TYPE_HTML)->sole()->path);

            $this->assertStringContainsString('border-top:12px dashed #ffd166;', $html);
            $this->assertStringContainsString('transform:rotate(-4deg);', $html);

            $browser->screenshot('line-in-editor');
        });
    }

    /** The first element of the document the editor holds, as plain data. */
    private function element(Browser $browser): array
    {
        return $browser->script('return JSON.parse(JSON.stringify('.self::EDITOR.'.doc.elements[0]));')[0];
    }

    private function setValue(Browser $browser, string $selector, string $value, string $event): void
    {
        $css = '[dusk="'.substr($selector, 1).'"]';

        $browser->script("const el = document.querySelector('{$css}'); el.value = '{$value}'; el.dispatchEvent(new Event('{$event}', { bubbles: true }));");
    }
}
