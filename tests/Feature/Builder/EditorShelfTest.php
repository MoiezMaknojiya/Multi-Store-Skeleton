<?php

use App\Models\BuilderAd;
use App\Models\BuilderAsset;
use App\Models\Organization;

/*
|--------------------------------------------------------------------------
| The editor is handed every file its design names (live, 2026-10-23)
|--------------------------------------------------------------------------
|
| The editor took the newest 200 files of its shelf and nothing else. Once the platform's shelf held more (263, after the
| GAMA promos), an older design opened with holes where its pictures were — and its poster, drawn from the stage as it is
| shown, would have been saved with them. It is now handed the newest BuilderAsset::EDITOR_SHELF files and, beside them,
| every file the design names, its draft and its published version, from its own shelf alone.
|
*/

function element(int $assetId, string $id): array
{
    return [
        'id' => $id, 'type' => 'image', 'name' => 'Picture', 'assetId' => $assetId,
        'x' => 0, 'y' => 0, 'w' => 400, 'h' => 300, 'rotation' => 0, 'opacity' => 1, 'z' => 0, 'locked' => false, 'visible' => true,
        'style' => [], 'animations' => [],
    ];
}

test('an older design is handed the files it names beside the newest, and never another shelf\'s', function () {
    $old = BuilderAsset::factory()->create(['organization_id' => null, 'title' => 'Old brick wall', 'created_at' => now()->subYear()]);
    $published = BuilderAsset::factory()->create(['organization_id' => null, 'title' => 'Old plate', 'created_at' => now()->subYear()]);
    $theirs = BuilderAsset::factory()->create(['organization_id' => Organization::factory()->create()->id, 'created_at' => now()->subYear()]);
    BuilderAsset::factory()->count(BuilderAsset::EDITOR_SHELF)->create(['organization_id' => null]);

    $draft = BuilderAd::blankDocument('landscape');
    $draft['elements'] = [element($old->id, 'el_old'), element($theirs->id, 'el_theirs')];
    $live = BuilderAd::blankDocument('landscape');
    $live['elements'] = [element($published->id, 'el_published')];
    $ad = BuilderAd::factory()->create(['organization_id' => null, 'document' => $draft]);
    $ad->forceFill(['published_document' => $live])->save();

    $ids = collect($this->actingAs(createSuperAdmin())->get("/builder/{$ad->id}")->assertOk()->viewData('assets'))->pluck('id');

    expect($ids)->toHaveCount(BuilderAsset::EDITOR_SHELF + 2)
        ->toContain($old->id)
        ->toContain($published->id)
        ->not->toContain($theirs->id);
});

test('a new ad is handed the newest files of its shelf alone', function () {
    $old = BuilderAsset::factory()->create(['organization_id' => null, 'created_at' => now()->subYear()]);
    BuilderAsset::factory()->count(BuilderAsset::EDITOR_SHELF)->create(['organization_id' => null]);

    $ids = collect($this->actingAs(createSuperAdmin())->get('/builder/create?orientation=landscape')->assertOk()->viewData('assets'))->pluck('id');

    expect($ids)->toHaveCount(BuilderAsset::EDITOR_SHELF)->not->toContain($old->id);
});
