<?php

namespace Tests\Browser;

use App\Models\BuilderAd;
use App\Models\BuilderFont;
use App\Models\Media;
use App\Models\Store;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Storage;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Typography in the real panel (docs/AD-BUILDER-SPEC.md §7, stage 2): a font is chosen, the rare
 * controls are opened with "Show more", and what the panel set is what the published page carries.
 *
 * The font is planted as already installed rather than downloaded: a browser test must not depend on
 * Google being reachable, and the download itself is covered by `AdFontTest` with a faked Google.
 */
class AdTypographyFlowTest extends DuskTestCase
{
    use DatabaseMigrations;

    public function test_a_designer_chooses_a_font_and_sets_the_type(): void
    {
        $this->seedSuperAdmin();
        $store = Store::factory()->create(['name' => 'Alpha Mart']);
        $designer = $this->storeMember(
            $store,
            ['ad-view', 'ad-store', 'ad-update'],
            'designer@example.com',
            'Designer',
        );

        // Already installed, as if somebody had picked it last week. The stylesheet names its file by
        // the disk's own address, the way GoogleFontInstaller writes it — /dusk-storage here, not the
        // /storage a real installation serves, which would send the editor to a file that is not there.
        $face = Storage::disk('public')->url('fonts/poppins/400.woff2');
        Storage::disk('public')->put('fonts/poppins/font.css', "@font-face{font-family:'Poppins';font-weight:400;src:url('{$face}') format('woff2');}");
        Storage::disk('public')->put('fonts/poppins/400.woff2', 'woff2-bytes');
        BuilderFont::create([
            'family' => 'Poppins', 'slug' => 'poppins', 'kind' => 'sans',
            'weights' => [400, 500, 600, 700],
            'files' => ['fonts/poppins/400.woff2'],
            'css_path' => 'fonts/poppins/font.css',
            'size' => 1024,
        ]);

        $this->browse(function (Browser $browser) use ($designer, $store) {
            $this->freshSession($browser);
            $browser->loginAs($designer);
            $this->switchToStore($browser, $store);

            $browser->visit('/builder/create');
            $this->waitForAlpine($browser);
            $browser->waitFor('@ad-stage');

            /* ── A line of text, and a font for it ──────────────────────── */
            $this->jsClick($browser, '@add-text');
            $browser->waitFor('@text-font');

            $this->clickAndAwait($browser, '@text-font', fn (Browser $b) => $b->waitFor('@font-picker', 3));
            $browser->waitFor('@font-poppins')
                ->assertSeeIn('@font-poppins', 'Ready');       // installed, so nothing to download

            $this->jsClick($browser, '@font-poppins');
            $browser->waitUntilMissing('@font-picker', 5)
                ->assertSeeIn('@text-font', 'Poppins');

            /* ── The rare controls, one click away ──────────────────────── */
            $browser->assertMissing('@text-letter-spacing');   // hidden until Show more
            $this->jsClick($browser, '@text-show-more');
            $browser->waitFor('@text-letter-spacing');

            $this->setNumber($browser, '@text-line-height', '0.9');
            $this->setNumber($browser, '@text-letter-spacing', '-4');
            $this->setSelect($browser, '@text-transform', 'uppercase');
            $this->jsClick($browser, '@text-align-center');
            $this->jsClick($browser, '@text-shadow-toggle');
            $browser->waitFor('@text-shadow-blur');

            // An outline too, and a panel behind the words that is then taken away again — the two
            // controls that only make sense once you can see them.
            $this->jsClick($browser, '@text-stroke-toggle');
            $browser->waitFor('@text-stroke-width');

            $this->setColour($browser, '@text-background', '#101828');
            $browser->waitFor('@text-background-clear');
            $this->jsClick($browser, '@text-background-clear');
            $browser->waitUntilMissing('@text-background-clear', 5);

            /* ── Publish, and read what the television will get ─────────── */
            $this->jsType($browser, '@ad-name', 'Typography');
            $this->jsClick($browser, '@ad-publish');

            $browser->waitUsing(25, 250, fn () => Media::where('type', Media::TYPE_HTML)->exists());

            $ad = BuilderAd::firstWhere('name', 'Typography');
            $style = $ad->document['elements'][0]['style'];

            $this->assertSame('Poppins', $style['fontFamily']);
            $this->assertSame(0.9, (float) $style['lineHeight']);
            $this->assertSame(-4, (int) $style['letterSpacing']);
            $this->assertSame('uppercase', $style['textTransform']);
            $this->assertSame('center', $style['align']);
            $this->assertIsArray($style['textShadow']);

            $html = Storage::disk('public')->get(Media::sole()->path);

            $this->assertStringContainsString("font-family:'Poppins', sans-serif;", $html);
            $this->assertStringContainsString('line-height:0.9;', $html);
            $this->assertStringContainsString('letter-spacing:-4px;', $html);
            $this->assertStringContainsString('text-transform:uppercase;', $html);
            $this->assertStringContainsString('text-align:center;', $html);
            $this->assertStringContainsString('text-shadow:', $html);

            // The face itself travels inside the page: the television's sandboxed frame could fetch no font.
            $this->assertStringContainsString("@font-face{font-family:'Poppins';font-style:normal;font-weight:400;", $html);
            $this->assertStringContainsString('src:url(data:font/woff2;base64,'.base64_encode('woff2-bytes').')', $html);
            $this->assertStringNotContainsString('fonts/poppins/font.css', $html);
        });
    }

    /** Type a number into a panel field and let Alpine hear about it. */
    private function setNumber(Browser $browser, string $selector, string $value): void
    {
        $this->jsType($browser, $selector, $value);
        $css = '[dusk="'.substr($selector, 1).'"]';
        $browser->script("document.querySelector('{$css}').dispatchEvent(new Event('change', { bubbles: true }));");
    }

    /** Set a colour input and let Alpine hear about it. */
    private function setColour(Browser $browser, string $selector, string $value): void
    {
        $this->setSelect($browser, $selector, $value);
    }

    /** Choose an option and let Alpine hear about it. */
    private function setSelect(Browser $browser, string $selector, string $value): void
    {
        $css = '[dusk="'.substr($selector, 1).'"]';
        $browser->script(
            "const el = document.querySelector('{$css}');"
            ."el.value = '{$value}';"
            ."el.dispatchEvent(new Event('change', { bubbles: true }));"
        );
    }
}
