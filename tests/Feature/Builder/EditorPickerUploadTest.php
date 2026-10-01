<?php

use App\Models\BuilderAd;
use App\Models\Store;

/*
|--------------------------------------------------------------------------
| The editor's picker takes a file, and the editor comes as a script of its own (owner, 2026-09-30)
|--------------------------------------------------------------------------
|
| The box is in the picker for whoever may add to the shelf — Create Ads, as the shelf's own upload asks — and not
| for anybody else; above the stores a new ad names its shop before a file can go anywhere; and only the editor's
| page asks for the editor's script.
|
*/

/** The editor page's HTML for a person, in a store or above them. */
function editorPage($person, ?Store $store, string $uri = '/builder/create?orientation=landscape'): string
{
    $request = test()->actingAs($person);

    if ($store !== null) {
        $request = $request->withSession(['current_store_id' => $store->id]);
    }

    return $request->get($uri)->assertOk()->getContent();
}

/** What a component on the page was handed (the Js::from its x-data carries) — the one on the element named $dusk. */
function alpineConfig(string $html, string $component, ?string $dusk = null): ?array
{
    $pattern = '/'.$component.'\((JSON\.parse\(\'.*?\'\))\)"'.($dusk !== null ? '[^>]*dusk="'.$dusk.'"' : '').'/s';

    if (! preg_match($pattern, $html, $m)) {
        return null;
    }

    // Js::from writes JSON.parse('…') with the JSON inside as a string of its own: read the string, then the JSON.
    preg_match("/JSON\\.parse\\('(.*)'\\)/s", $m[1], $json);

    return json_decode((string) json_decode('"'.$json[1].'"'), true);
}

test('a store\'s designer finds the box in the picker; somebody who may only change ads does not', function () {
    $store = Store::factory()->create();
    $designer = createStoreUser($store, ['ad-view', 'ad-store', 'ad-update'], 'Designer');
    $changer = createStoreUser($store, ['ad-view', 'ad-update'], 'Changer');
    $ad = BuilderAd::factory()->create(['store_id' => $store->id]);

    $html = editorPage($designer, $store);
    expect($html)->toContain('dusk="picker-upload"')->toContain('dusk="picker-dropzone"')
        ->and(alpineConfig($html, 'uploadDropzone', 'picker-dropzone'))
        ->toMatchArray(['purpose' => 'asset', 'mode' => 'add', 'addUrl' => '/builder/assets', 'maxVideoSeconds' => 30, 'needsStore' => null])
        ->and(alpineConfig($html, 'adEditor')['canUpload'] ?? null)->toBeTrue();

    // Changing an ad needs no new file: without Create Ads there is no box.
    $html = editorPage($changer, $store, "/builder/{$ad->id}");
    expect($html)->not->toContain('dusk="picker-upload"')
        ->and(alpineConfig($html, 'adEditor')['canUpload'] ?? null)->toBeFalse();
});

test('above the stores a new ad is for every shop at once, or names its shop first for somebody who may not make one', function () {
    // With Update Shared Ads the Shop list starts at All shops (owner, 2026-10-01): a file dropped into the picker goes
    // to the shelf shared with every shop at once — the error the owner met ("Choose the shop this ad is for first")
    // is not said.
    $html = editorPage(createSuperAdmin(), null);

    expect(alpineConfig($html, 'uploadDropzone', 'picker-dropzone')['needsStore'] ?? null)->toBeNull()
        ->and(alpineConfig($html, 'adEditor'))->toMatchArray(['canUpload' => true, 'canShare' => true, 'storeId' => null])
        ->and($html)->toContain('<option value="">All shops</option>');

    // Without it a shop comes first, as before.
    $html = editorPage(createPlatformUser(['ad-view', 'ad-store', 'ad-update'], 'Platform designer'), null);

    expect(alpineConfig($html, 'uploadDropzone', 'picker-dropzone')['needsStore'] ?? null)->toBe('Choose the shop this ad is for first, at the top.')
        ->and(alpineConfig($html, 'adEditor'))->toMatchArray(['canUpload' => true, 'canShare' => false])
        ->and($html)->toContain('<option value="">Choose a shop…</option>');
});

test('only the editor\'s page asks for the editor\'s script', function () {
    $store = Store::factory()->create();
    $designer = createStoreUser($store, ['ad-view', 'ad-store', 'ad-update', 'media-view'], 'Designer');

    expect(editorPage($designer, $store))->toMatch('/<link rel="modulepreload" href="[^"]*\/build\/assets\/editor-[^"]+\.js">/');

    foreach (['/dashboard', '/builder', '/builder/assets', '/media'] as $page) {
        $html = $this->actingAs($designer)->withSession(['current_store_id' => $store->id])->get($page)->assertOk()->getContent();
        expect($html)->not->toContain('/build/assets/editor-');
    }
});
