<?php

use App\Models\Organization;

/*
|--------------------------------------------------------------------------
| The app's icons are the owner's logo, in every place a browser or a home screen asks for one
|--------------------------------------------------------------------------
|
| Owner, 2026-10-09: ".ico banao jo industrial standard ha woo follow kar k meri app mein lagao yeh mera logo ha". The
| set is the one browsers ask for today — an SVG favicon, favicon.ico of 16, 32 and 48 px, the 180 px apple-touch-icon
| and a manifest naming 192 and 512 px pictures, one of them maskable — drawn by scripts/make-brand-icons.php. Every
| layout links them, and the panel draws the logo as its brand mark.
|
*/

/** Width and height of a PNG in public/. */
function pngSize(string $file): array
{
    $size = getimagesize(public_path($file));

    expect($size)->not->toBeFalse()
        ->and($size['mime'])->toBe('image/png');

    return [$size[0], $size[1]];
}

test('every icon is in public/ at the size it is named for', function () {
    expect(pngSize('apple-touch-icon.png'))->toBe([180, 180])
        ->and(pngSize('icon-192.png'))->toBe([192, 192])
        ->and(pngSize('icon-512.png'))->toBe([512, 512])
        ->and(pngSize('icon-maskable-512.png'))->toBe([512, 512]);

    // favicon.ico holds a 16, a 32 and a 48 px picture, each a PNG that opens.
    $ico = file_get_contents(public_path('favicon.ico'));
    $header = unpack('vreserved/vtype/vcount', $ico);
    expect($header)->toBe(['reserved' => 0, 'type' => 1, 'count' => 3]);

    $sizes = [];
    for ($i = 0; $i < 3; $i++) {
        $entry = unpack('Cwidth/Cheight/Ccolours/Creserved/vplanes/vbits/Vlength/Voffset', substr($ico, 6 + 16 * $i, 16));
        $picture = getimagesizefromstring(substr($ico, $entry['offset'], $entry['length']));
        expect($picture[0])->toBe($entry['width'])->and($picture[1])->toBe($entry['height']);
        $sizes[] = $entry['width'];
    }
    expect($sizes)->toBe([16, 32, 48]);

    // The SVGs are square and carry none of the 19 KB of provenance the source file has.
    foreach (['icon.svg', 'brand/logo.svg'] as $svg) {
        $content = file_get_contents(public_path($svg));
        expect($content)->toStartWith('<svg')->not->toContain('<metadata')->not->toContain('c2pa')
            ->and(preg_match('~viewBox="[-\d.]+ [-\d.]+ ([\d.]+) ([\d.]+)"~', $content, $box))->toBe(1)
            ->and($box[1])->toBe($box[2]);
    }

    // The old play-button icons are gone, and nothing names them.
    expect(is_dir(public_path('player-icons')))->toBeFalse();
});

test('both manifests name the logo, and every picture they name is there at its size', function (string $uri, string $name) {
    config(['app.name' => 'Test App']);
    $manifest = $this->get($uri)->assertOk()->assertHeader('Content-Type', 'application/manifest+json')->json();

    expect($manifest['name'])->toBe($name)
        ->and(collect($manifest['icons'])->pluck('purpose')->all())->toBe(['any', 'any', 'maskable']);

    foreach ($manifest['icons'] as $icon) {
        [$width, $height] = pngSize(ltrim($icon['src'], '/'));
        expect("{$width}x{$height}")->toBe($icon['sizes']);
    }
})->with([
    'the panel' => ['/site.webmanifest', 'Test App'],
    'the player' => ['/player.webmanifest', 'Test App Player'],
]);

test('every layout links the icons, and the panel draws the logo beside its name', function () {
    $links = [
        '<link rel="icon" href="/favicon.ico" sizes="32x32">',
        '<link rel="icon" href="/icon.svg" type="image/svg+xml">',
        '<link rel="apple-touch-icon" href="/apple-touch-icon.png">',
        '<link rel="manifest" href="'.route('site.manifest').'">',
    ];
    $mark = '<img src="/brand/logo.svg" alt=""';

    $organization = Organization::factory()->create();
    $designer = createOrganizationUser($organization, ['ad-view', 'ad-store', 'ad-update'], 'Designer');
    $twoOrganizations = inASecondOrganization(createOrganizationUser($organization, ['ad-view'], 'Looker'));

    $pages = [
        'guest' => fn () => $this->get('/login'),
        'app' => fn () => $this->actingAs($designer)->withSession(['current_organization_id' => $organization->id])->get('/dashboard'),
        'focused' => fn () => $this->actingAs($twoOrganizations)->withSession([])->get('/select-organization'),
        'error' => fn () => $this->get('/no-such-page-at-all'),
    ];

    foreach ($pages as $layout => $request) {
        $html = $request()->getContent();
        foreach ($links as $link) {
            expect($html)->toContain($link);
        }
        expect($html)->toContain($mark)->not->toContain('M13 10V3L4 14h7v7l9-11h-7z');
    }

    // The editor's own layout has no brand mark, but the tab still shows the logo.
    $editor = $this->actingAs($designer)->withSession(['current_organization_id' => $organization->id])
        ->get('/builder/create?orientation=landscape')->assertOk()->getContent();
    foreach ($links as $link) {
        expect($editor)->toContain($link);
    }

    // The television's page names the same pictures beside its own manifest.
    $this->get('/player')->assertOk()
        ->assertSee('<link rel="icon" href="/icon.svg" type="image/svg+xml">', false)
        ->assertSee('<link rel="apple-touch-icon" href="/apple-touch-icon.png">', false)
        ->assertDontSee('player-icons', false);
});
