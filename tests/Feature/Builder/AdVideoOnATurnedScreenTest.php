<?php

use App\Models\BuilderAd;
use App\Models\BuilderAsset;
use App\Models\Organization;
use App\Services\AdCompiler;

/*
|--------------------------------------------------------------------------
| Every video on a published page is drawn a touch short of opaque (Roadstar 4, 2026-10-27)
|--------------------------------------------------------------------------
|
| Chromium puts a video on a hardware plane of its own only while its quad is fully opaque (viz,
| OverlayCandidateFactory::FromDrawQuad: kFailOpacity), and a Fire TV's plane never turns with the stage the
| player rotates for a portrait screen. The player's own CSS keeps its video off that plane with an opacity of
| .999 — but an Ad Builder page plays in a frame of its own, where that CSS does not reach. So the compiler
| writes the same rule into every page (a page cannot know which way its screen is turned), holds a background
| layer's own inline opacity under it, and VERSION moved, so the next deploy writes every published page again
| (docs/AD-BUILDER-SPEC.md §15, RecompilePublishedAdsTest). TurnedScreenVideoTest reads the computed opacity
| inside the frame on a real television.
|
*/

beforeEach(function () {
    $this->organization = Organization::factory()->create();
    $this->clip = BuilderAsset::factory()->video()->create(['organization_id' => $this->organization->id, 'path' => 'builder/1/assets/loop.mp4']);
});

/** The page of a document with these background layers and elements. */
function pageWithVideos(Organization $organization, array $layers = [], array $elements = []): string
{
    $document = BuilderAd::blankDocument();
    $document['stage']['background']['layers'] = $layers;
    $document['elements'] = $elements;

    return app(AdCompiler::class)->compile(BuilderAd::factory()->create(['organization_id' => $organization->id, 'document' => $document]));
}

test('every page carries the rule, a video on it or not', function () {
    $blank = pageWithVideos($this->organization);

    expect(AdCompiler::VIDEO_OPACITY)->toBeLessThan(1)->toBeGreaterThan(0.99)
        ->and($blank)->toContain('video { opacity: 0.999; }')
        ->not->toContain('<video');
});

test('a background video is written a touch short of opaque whatever its layer says; a faded one keeps its fade', function () {
    $html = pageWithVideos($this->organization, [
        ['id' => 'a', 'type' => 'video', 'assetId' => $this->clip->id, 'opacity' => 1, 'blend' => 'normal'],
        ['id' => 'b', 'type' => 'video', 'assetId' => $this->clip->id, 'opacity' => 0.6, 'blend' => 'screen', 'fit' => 'contain'],
        // Held inside the limit — and under the rule.
        ['id' => 'c', 'type' => 'video', 'assetId' => $this->clip->id, 'opacity' => 7],
        // Only a video pays the plane: every other layer is written as it was.
        ['id' => 'd', 'type' => 'color', 'color' => '#1e3a8a', 'opacity' => 1],
    ]);

    expect($html)->toContain('playsinline style="opacity:0.6;mix-blend-mode:screen;object-fit:contain;"')
        ->toContain('<div class="ad-layer" style="opacity:1;mix-blend-mode:normal;background-color:#1e3a8a;">')
        ->not->toContain('playsinline style="opacity:1;')
        ->and(substr_count($html, 'playsinline style="opacity:0.999;mix-blend-mode:normal;object-fit:cover;"'))->toBe(2);
});

test('a video element carries no opacity of its own on the video, so the page’s rule governs it', function () {
    $html = pageWithVideos($this->organization, elements: [[
        'id' => 'el_video', 'type' => 'video', 'name' => 'Clip', 'x' => 0, 'y' => 0, 'w' => 320, 'h' => 180,
        'rotation' => 0, 'opacity' => 0.8, 'z' => 0, 'locked' => false, 'visible' => true, 'assetId' => $this->clip->id,
        'style' => ['fit' => 'contain'], 'animations' => [],
    ]]);

    preg_match('/<video class="ad-content" [^>]*>/', $html, $video);

    expect($video)->not->toBeEmpty()
        ->and($video[0])->toContain('style="object-fit:contain;')
        ->not->toContain('opacity')
        // The element's own fade stays on its box, as it was.
        ->and($html)->toContain('width:320px;height:180px;opacity:0.8;z-index:1;');
});

test('the pages on the screens are written again on the next deploy: one the compiler of 2026-09-24 wrote is out of date', function () {
    expect(AdCompiler::VERSION)->toBeGreaterThanOrEqual('2026-10-27')
        ->and(AdCompiler::wroteCurrent('<html><head><meta name="ad-compiler" content="2026-09-24"></head></html>'))->toBeFalse();
});
