<?php

use App\Models\BuilderAd;
use App\Models\BuilderFont;
use App\Models\Media;
use App\Models\Store;
use App\Services\AdCompiler;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Typography and the fonts behind it
|--------------------------------------------------------------------------
|
| docs/AD-BUILDER-SPEC.md §7a. A font picked in the editor is downloaded ONCE and served from this
| server for good, because the television showing the advert may have no way out to the internet —
| and an advert in a fallback face is the wrong advert. Only the families on our own list can be
| installed, so a font name typed by hand can never make the server fetch an arbitrary URL.
|
*/

beforeEach(function () {
    Storage::fake('public');

    $this->store = Store::factory()->create(['name' => 'Alpha Mart']);
    $this->designer = createStoreUser($this->store, ['ad-view', 'ad-store', 'ad-update'], 'Designer');
    $this->actingAs($this->designer)->withSession(['current_store_id' => $this->store->id]);
});

/** Google's own answer, in the shape the CSS API really sends: one block per weight per subset. */
function googleStylesheet(string $family = 'Poppins'): string
{
    return <<<CSS
    /* latin-ext */
    @font-face {
      font-family: '{$family}';
      font-style: normal;
      font-weight: 400;
      font-display: swap;
      src: url(https://fonts.gstatic.com/s/poppins/v21/latin-ext-400.woff2) format('woff2');
      unicode-range: U+0100-02AF, U+0304-0308;
    }
    /* latin */
    @font-face {
      font-family: '{$family}';
      font-style: normal;
      font-weight: 400;
      font-display: swap;
      src: url(https://fonts.gstatic.com/s/poppins/v21/latin-400.woff2) format('woff2');
      unicode-range: U+0000-00FF, U+0131;
    }
    /* latin */
    @font-face {
      font-family: '{$family}';
      font-style: normal;
      font-weight: 700;
      font-display: swap;
      src: url(https://fonts.gstatic.com/s/poppins/v21/latin-700.woff2) format('woff2');
      unicode-range: U+0000-00FF, U+0131;
    }
    CSS;
}

test('the picker offers the system faces and our list, saying what is ready', function () {
    $fonts = collect($this->getJson('/builder/fonts')->assertOk()->json('fonts'));

    // The system faces come first and need no download at all.
    expect($fonts->first()['family'])->toBe('Arial')
        ->and($fonts->first()['installed'])->toBeTrue()
        ->and($fonts->pluck('family'))->toContain('Poppins', 'Playfair Display', 'Anton', 'Noto Nastaliq Urdu')
        ->and($fonts->firstWhere('family', 'Poppins')['installed'])->toBeFalse();
});

test('picking a font downloads it once and serves it from here', function () {
    Http::fake([
        'fonts.googleapis.com/*' => Http::response(googleStylesheet()),
        'fonts.gstatic.com/*' => Http::response('woff2-bytes'),
    ]);

    $response = $this->postJson('/builder/fonts', ['family' => 'Poppins'])->assertOk();

    $font = BuilderFont::sole();

    expect($font->family)->toBe('Poppins')
        ->and($font->slug)->toBe('poppins')
        ->and($font->availableWeights())->toBe([400, 500, 600, 700])
        ->and($font->files)->toHaveCount(3)          // two latin subsets at 400, one at 700
        ->and($font->installed_by)->toBe($this->designer->id)
        ->and($response->json('font.installed'))->toBeTrue();

    // The files and a stylesheet of our own are on the disk.
    foreach ($font->files as $file) {
        Storage::disk('public')->assertExists($file);
    }

    $css = Storage::disk('public')->get($font->css_path);

    expect($css)->toContain("font-family:'Poppins'")
        ->toContain('/storage/fonts/poppins/400-normal-')
        ->toContain('unicode-range:U+0000-00FF, U+0131;')
        ->not->toContain('fonts.gstatic.com');       // nothing points at Google any more
});

test('installing the same font twice costs nothing', function () {
    Http::fake([
        'fonts.googleapis.com/*' => Http::response(googleStylesheet()),
        'fonts.gstatic.com/*' => Http::response('woff2-bytes'),
    ]);

    $this->postJson('/builder/fonts', ['family' => 'Poppins'])->assertOk();
    Http::fake();       // any further call to Google would now answer 200 with nothing — and must not happen

    $this->postJson('/builder/fonts', ['family' => 'Poppins'])->assertOk();

    expect(BuilderFont::count())->toBe(1)
        ->and(BuilderFont::sole()->files)->toHaveCount(3);
});

test('only the families on our own list can be installed', function () {
    Http::fake();

    foreach (['Comic Nonsense', 'https://evil.example/font.css', '../../etc/passwd', ''] as $family) {
        $this->postJson('/builder/fonts', ['family' => $family])->assertStatus(422);
    }

    expect(BuilderFont::count())->toBe(0);
    Http::assertNothingSent();
});

test('a published ad carries its own fonts, and only the ones it uses', function () {
    Http::fake([
        'fonts.googleapis.com/*' => Http::response(googleStylesheet()),
        'fonts.gstatic.com/*' => Http::response('woff2-bytes'),
    ]);

    $this->postJson('/builder/fonts', ['family' => 'Poppins'])->assertOk();
    $this->postJson('/builder/fonts', ['family' => 'Lora'])->assertOk();

    $document = BuilderAd::blankDocument();
    $document['elements'] = [[
        'id' => 'el_1', 'type' => 'text', 'x' => 0, 'y' => 0, 'w' => 900, 'h' => 200, 'z' => 0,
        'text' => 'Winter sale',
        'style' => ['fontFamily' => 'Poppins', 'fontSize' => 120, 'fontWeight' => 700],
        'animations' => [],
    ]];

    $ad = BuilderAd::factory()->create(['store_id' => $this->store->id, 'name' => 'Winter', 'document' => $document]);
    $this->postJson("/builder/{$ad->id}/publish")->assertOk();

    $html = Storage::disk('public')->get(Media::sole()->path);

    // Inside the page itself: a television plays it in a sandboxed frame, whose opaque origin makes every
    // font file on our server a cross-origin fetch — and the browser refused them all.
    expect($html)->toContain("@font-face{font-family:'Poppins';font-style:normal;font-weight:700;")
        ->toContain('src:url(data:font/woff2;base64,'.base64_encode('woff2-bytes').") format('woff2')")
        ->not->toContain('/storage/fonts/')              // nothing left to fetch
        ->not->toContain("font-family:'Lora'")           // not used by this design
        ->not->toContain('fonts.googleapis.com')         // a shop's television never asks Google
        ->not->toContain('fonts.gstatic.com')
        ->toContain("font-family:'Poppins', sans-serif;");

    // Only the weight the design asks for, and only the subset its words need: the latin 700 file.
    expect(substr_count($html, '@font-face'))->toBe(1);
});

test('a weight the family does not have is drawn with the one a browser would pick', function () {
    Http::fake([
        'fonts.googleapis.com/*' => Http::response(googleStylesheet()),
        'fonts.gstatic.com/*' => Http::sequence()->push('latin-ext-400')->push('latin-400')->push('latin-700'),
    ]);

    $this->postJson('/builder/fonts', ['family' => 'Poppins'])->assertOk();   // 400 and 700 only

    $compiled = function (int $weight): string {
        $document = BuilderAd::blankDocument();
        $document['elements'] = [[
            'id' => 'el_1', 'type' => 'text', 'x' => 0, 'y' => 0, 'w' => 900, 'h' => 200, 'z' => 0,
            'text' => 'Sale', 'style' => ['fontFamily' => 'Poppins', 'fontWeight' => $weight], 'animations' => [],
        ]];

        return app(AdCompiler::class)->compile(BuilderAd::factory()->make(['store_id' => $this->store->id, 'document' => $document]));
    };

    // CSS font matching: 800 → the heavier 700; 300 → the lighter 400; 450 → nothing up to 500, so 400.
    expect($compiled(800))->toContain('base64,'.base64_encode('latin-700'))->not->toContain(base64_encode('latin-400'));
    expect($compiled(300))->toContain('base64,'.base64_encode('latin-400'))->not->toContain(base64_encode('latin-700'));
    expect($compiled(450))->toContain('base64,'.base64_encode('latin-400'))->not->toContain(base64_encode('latin-700'));
});

test('only the subsets the words need are carried: an Urdu headline brings the Arabic file, a Latin one does not', function () {
    Storage::disk('public')->put('fonts/noto-nastaliq-urdu/400-normal-0.woff2', 'arabic-bytes');
    Storage::disk('public')->put('fonts/noto-nastaliq-urdu/400-normal-1.woff2', 'latin-bytes');
    Storage::disk('public')->put('fonts/noto-nastaliq-urdu/font.css', implode("\n", [
        "@font-face{font-family:'Noto Nastaliq Urdu';font-style:normal;font-weight:400;font-display:swap;src:url('http://localhost/storage/fonts/noto-nastaliq-urdu/400-normal-0.woff2') format('woff2');unicode-range:U+0600-06FF, U+FB50-FDFF;}",
        "@font-face{font-family:'Noto Nastaliq Urdu';font-style:normal;font-weight:400;font-display:swap;src:url('http://localhost/storage/fonts/noto-nastaliq-urdu/400-normal-1.woff2') format('woff2');unicode-range:U+0000-00FF, U+2000-206F;}",
    ]));
    BuilderFont::create([
        'family' => 'Noto Nastaliq Urdu', 'slug' => 'noto-nastaliq-urdu', 'kind' => 'script', 'weights' => [400],
        'files' => ['fonts/noto-nastaliq-urdu/400-normal-0.woff2', 'fonts/noto-nastaliq-urdu/400-normal-1.woff2'],
        'css_path' => 'fonts/noto-nastaliq-urdu/font.css', 'size' => 1,
    ]);

    $compiled = function (string $words): string {
        $document = BuilderAd::blankDocument();
        $document['elements'] = [[
            'id' => 'el_1', 'type' => 'text', 'x' => 0, 'y' => 0, 'w' => 900, 'h' => 200, 'z' => 0,
            'text' => $words, 'style' => ['fontFamily' => 'Noto Nastaliq Urdu'], 'animations' => [],
        ]];

        return app(AdCompiler::class)->compile(BuilderAd::factory()->make(['store_id' => $this->store->id, 'document' => $document]));
    };

    expect($compiled('زبردست'))->toContain(base64_encode('arabic-bytes'))->not->toContain(base64_encode('latin-bytes'))
        ->toContain('unicode-range:U+0600-06FF, U+FB50-FDFF;');
    expect($compiled('Deal'))->toContain(base64_encode('latin-bytes'))->not->toContain(base64_encode('arabic-bytes'));
    expect($compiled('زبردست Deal'))->toContain(base64_encode('arabic-bytes'))->toContain(base64_encode('latin-bytes'));
});

test('a variable font — one file for every weight — is carried once, for the whole range', function () {
    Storage::disk('public')->put('fonts/noto-nastaliq-urdu/400-normal-0.woff2', 'one-variable-file');
    Storage::disk('public')->put('fonts/noto-nastaliq-urdu/700-normal-1.woff2', 'one-variable-file');
    Storage::disk('public')->put('fonts/noto-nastaliq-urdu/font.css', implode("\n", [
        "@font-face{font-family:'Noto Nastaliq Urdu';font-style:normal;font-weight:400;font-display:swap;src:url('http://localhost/storage/fonts/noto-nastaliq-urdu/400-normal-0.woff2') format('woff2');unicode-range:U+0600-06FF;}",
        "@font-face{font-family:'Noto Nastaliq Urdu';font-style:normal;font-weight:700;font-display:swap;src:url('http://localhost/storage/fonts/noto-nastaliq-urdu/700-normal-1.woff2') format('woff2');unicode-range:U+0600-06FF;}",
    ]));
    BuilderFont::create([
        'family' => 'Noto Nastaliq Urdu', 'slug' => 'noto-nastaliq-urdu', 'kind' => 'urdu', 'weights' => [400, 700],
        'files' => ['fonts/noto-nastaliq-urdu/400-normal-0.woff2', 'fonts/noto-nastaliq-urdu/700-normal-1.woff2'],
        'css_path' => 'fonts/noto-nastaliq-urdu/font.css', 'size' => 1,
    ]);

    $text = fn (string $id, string $words, int $weight) => [
        'id' => $id, 'type' => 'text', 'x' => 0, 'y' => 0, 'w' => 900, 'h' => 200, 'z' => 0,
        'text' => $words, 'style' => ['fontFamily' => 'Noto Nastaliq Urdu', 'fontWeight' => $weight], 'animations' => [],
    ];
    $document = BuilderAd::blankDocument();
    $document['elements'] = [$text('el_1', 'زبردست', 400), $text('el_2', 'ڈیل', 700)];

    $html = app(AdCompiler::class)->compile(BuilderAd::factory()->make(['store_id' => $this->store->id, 'document' => $document]));

    expect(substr_count($html, '@font-face'))->toBe(1)
        ->and($html)->toContain("font-family:'Noto Nastaliq Urdu';font-style:normal;font-weight:400 700;")
        ->and(substr_count($html, base64_encode('one-variable-file')))->toBe(1);
});

test('the whole typography set reaches the page, and nothing else does', function () {
    $document = BuilderAd::blankDocument();
    $document['elements'] = [[
        'id' => 'el_1', 'type' => 'text', 'x' => 40, 'y' => 60, 'w' => 900, 'h' => 300, 'z' => 0,
        'text' => "Two\nlines",
        'style' => [
            'fontFamily' => 'Anton', 'fontSize' => 140, 'fontWeight' => 700, 'fontStyle' => 'italic',
            'color' => '#ffcc00', 'align' => 'center', 'verticalAlign' => 'center',
            'lineHeight' => 0.95, 'letterSpacing' => -2, 'wordSpacing' => 6,
            'textTransform' => 'uppercase', 'textDecoration' => 'underline',
            'padding' => 24, 'background' => '#101828', 'radius' => 18,
            'textShadow' => ['x' => 0, 'y' => 6, 'blur' => 18, 'color' => '#000000'],
            'textStroke' => ['width' => 3, 'color' => '#ffffff'],
        ],
        'animations' => [],
    ]];

    $ad = BuilderAd::factory()->create(['store_id' => $this->store->id, 'document' => $document]);
    $this->postJson("/builder/{$ad->id}/publish")->assertOk();

    $html = Storage::disk('public')->get(Media::sole()->path);

    expect($html)
        ->toContain('font-size:140px;')
        ->toContain('font-weight:700;')
        ->toContain('font-style:italic;')
        ->toContain('color:#ffcc00;')
        ->toContain('text-align:center;')
        ->toContain('justify-content:center;')
        ->toContain('line-height:0.95;')
        ->toContain('letter-spacing:-2px;')
        ->toContain('word-spacing:6px;')
        ->toContain('text-transform:uppercase;')
        ->toContain('text-decoration:underline;')
        ->toContain('padding:24px;')
        ->toContain('background:#101828;')
        ->toContain('border-radius:18px;')
        ->toContain('text-shadow:0px 6px 18px #000000;')
        ->toContain('-webkit-text-stroke:3px #ffffff;');
});

test('a shadow or an outline with a nonsense colour is simply not written', function () {
    $document = BuilderAd::blankDocument();
    $document['elements'] = [[
        'id' => 'el_1', 'type' => 'text', 'x' => 0, 'y' => 0, 'w' => 900, 'h' => 200, 'z' => 0,
        'text' => 'Sale',
        'style' => [
            'textShadow' => ['x' => 0, 'y' => 4, 'blur' => 8, 'color' => 'red; } body { display:none } .x{'],
            'textStroke' => ['width' => 2, 'color' => 'url(javascript:alert(1))'],
            'background' => 'nonsense',
        ],
        'animations' => [],
    ]];

    $ad = BuilderAd::factory()->create(['store_id' => $this->store->id, 'document' => $document]);
    $this->postJson("/builder/{$ad->id}/publish")->assertOk();

    $html = Storage::disk('public')->get(Media::sole()->path);

    expect($html)->not->toContain('text-shadow')
        ->not->toContain('-webkit-text-stroke')
        ->not->toContain('display:none')
        ->not->toContain('javascript:')
        ->not->toContain('background:nonsense');
});

test('a font list and an install both need the ad permissions', function () {
    $outsider = createStoreUser($this->store, ['screen-view'], 'Screens only');
    $this->actingAs($outsider)->withSession(['current_store_id' => $this->store->id]);

    $this->getJson('/builder/fonts')->assertForbidden();
    $this->postJson('/builder/fonts', ['family' => 'Poppins'])->assertForbidden();

    // Looking at the ads is enough to see the list, not to fetch a font from Google.
    $viewer = createStoreUser($this->store, ['ad-view'], 'Looks only');
    $this->actingAs($viewer)->withSession(['current_store_id' => $this->store->id]);

    $this->getJson('/builder/fonts')->assertOk();
    $this->postJson('/builder/fonts', ['family' => 'Poppins'])->assertForbidden();
});

test('the editor has its fonts for whoever opens it: Create Ads alone, or Update Ads alone', function () {
    Http::fake([
        'fonts.googleapis.com/*' => Http::response(googleStylesheet()),
        'fonts.gstatic.com/*' => Http::response('woff2-bytes'),
    ]);

    // Neither needs View Ads to open the editor, so neither may need it for the font picker.
    foreach ([['ad-store'], ['ad-update']] as $i => $permissions) {
        $designer = createStoreUser($this->store, $permissions, "Designer {$i}");
        $this->actingAs($designer)->withSession(['current_store_id' => $this->store->id]);

        $this->getJson('/builder/fonts')->assertOk();
        $this->postJson('/builder/fonts', ['family' => 'Poppins'])->assertOk();
    }

    expect(BuilderFont::count())->toBe(1);
});

test('a family written in another case is still set in its installed font', function () {
    // A browser matches font families without regard to case, so the page must too: "anton" is Anton.
    Storage::disk('public')->put('fonts/anton/400-normal-0.woff2', 'anton-bytes');
    Storage::disk('public')->put('fonts/anton/font.css', "@font-face{font-family:'Anton';font-style:normal;font-weight:400;font-display:swap;src:url('http://localhost/storage/fonts/anton/400-normal-0.woff2') format('woff2');}");
    BuilderFont::create([
        'family' => 'Anton', 'slug' => 'anton', 'kind' => 'display', 'weights' => [400],
        'files' => ['fonts/anton/400-normal-0.woff2'], 'css_path' => 'fonts/anton/font.css', 'size' => 1,
    ]);

    $document = BuilderAd::blankDocument();
    $document['elements'] = [[
        'id' => 'el_1', 'type' => 'text', 'x' => 0, 'y' => 0, 'w' => 900, 'h' => 200, 'z' => 0,
        'text' => 'Sale', 'style' => ['fontFamily' => 'anton'], 'animations' => [],
    ]];

    $html = app(AdCompiler::class)->compile(BuilderAd::factory()->make(['store_id' => $this->store->id, 'document' => $document]));

    expect($html)->toContain("@font-face{font-family:'Anton';")
        ->toContain(base64_encode('anton-bytes'))
        ->toContain("font-family:'anton', sans-serif;");
});
