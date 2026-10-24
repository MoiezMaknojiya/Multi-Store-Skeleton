<?php

use App\Models\ActivityLog;
use App\Models\BuilderAd;
use App\Models\BuilderAsset;
use App\Models\Organization;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| A shelf file is renamed (owner, 2026-10-24)
|--------------------------------------------------------------------------
|
| "Assets folder mein products ki images hain. Har image ke liye uska proper product name use karo, jaise 'Alani-12oz'": the Assets
| page renames a file, as the Media page does. Update Ads, under the delete's walls — an organization's own file inside its
| organization, the platform's above the organizations alone, another's not found — its name alone, logged, and every design
| that uses it unchanged, since a design names a file by its id.
|
*/

beforeEach(function () {
    Storage::fake('public');

    $this->alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
    $this->beta = Organization::factory()->create(['name' => 'Beta Deli']);
    $this->own = BuilderAsset::factory()->create(['organization_id' => $this->alpha->id, 'title' => 'IMG_0042']);
    $this->theirs = BuilderAsset::factory()->create(['organization_id' => $this->beta->id, 'title' => 'Beta file']);
    $this->platform = BuilderAsset::factory()->create(['organization_id' => null, 'title' => 'GAMA - ghost-03 (product)']);
});

function designer(object $test, array $permissions = ['ad-view', 'ad-update']): object
{
    $person = createOrganizationUser($test->alpha, $permissions, 'Designer');

    return $test->actingAs($person)->withSession(['current_organization_id' => $test->alpha->id]);
}

test('inside an organization its own file is renamed, logged, and the designs that use it keep it', function () {
    $document = BuilderAd::blankDocument('landscape');
    $document['elements'] = [['id' => 'el_1', 'type' => 'image', 'name' => 'Picture', 'assetId' => $this->own->id, 'x' => 0, 'y' => 0, 'w' => 10, 'h' => 10,
        'rotation' => 0, 'opacity' => 1, 'z' => 0, 'locked' => false, 'visible' => true, 'style' => [], 'animations' => []]];
    $ad = BuilderAd::factory()->create(['organization_id' => $this->alpha->id, 'document' => $document]);

    designer($this)->putJson("/builder/assets/{$this->own->id}", ['title' => '  Ghost-16oz-AW-Root-Beer  '])
        ->assertOk()->assertJsonPath('message', 'File renamed.')->assertJsonPath('asset.title', 'Ghost-16oz-AW-Root-Beer');

    expect($this->own->fresh()->title)->toBe('Ghost-16oz-AW-Root-Beer')
        ->and($ad->fresh()->document['elements'][0]['assetId'])->toBe($this->own->id)
        ->and(ActivityLog::where('action', 'ad_asset.renamed')->first())
        ->description->toBe('Renamed ad asset IMG_0042 to Ghost-16oz-AW-Root-Beer')
        ->organization_id->toBe($this->alpha->id);
});

test('an organization never reaches another organization\'s file or the platform\'s, and the shelf says what it may rename', function () {
    designer($this)->putJson("/builder/assets/{$this->theirs->id}", ['title' => 'Mine now'])->assertNotFound();
    designer($this)->putJson("/builder/assets/{$this->platform->id}", ['title' => 'Mine now'])->assertNotFound();

    $rows = collect(designer($this)->getJson('/builder/assets/data')->assertOk()->json('assets'))->keyBy('id');

    expect($rows->keys()->all())->toBe([$this->own->id])
        ->and($rows[$this->own->id]['can_rename'])->toBeTrue()
        ->and($this->theirs->fresh()->title)->toBe('Beta file')
        ->and($this->platform->fresh()->title)->toBe('GAMA - ghost-03 (product)');
});

test('without Update Ads a file is not renamed, and the shelf offers no Rename', function () {
    designer($this, ['ad-view'])->putJson("/builder/assets/{$this->own->id}", ['title' => 'Nope'])->assertForbidden();

    $row = designer($this, ['ad-view'])->getJson('/builder/assets/data')->json('assets.0');

    expect($row['can_rename'])->toBeFalse()->and($this->own->fresh()->title)->toBe('IMG_0042');
});

test('above the organizations the platform\'s file and an organization\'s are renamed', function () {
    $this->actingAs(createSuperAdmin());

    $this->putJson("/builder/assets/{$this->platform->id}", ['title' => 'Ghost-16oz-AW-Root-Beer'])->assertOk();
    $this->putJson("/builder/assets/{$this->theirs->id}", ['title' => 'Beta-Logo'])->assertOk();

    expect($this->platform->fresh()->title)->toBe('Ghost-16oz-AW-Root-Beer')
        ->and($this->theirs->fresh()->title)->toBe('Beta-Logo')
        ->and(ActivityLog::where('action', 'ad_asset.renamed')->whereNull('organization_id')->count())->toBe(1);
});

test('a title it cannot keep is refused in the form\'s words, in any shape, never a 500', function () {
    $this->actingAs(createSuperAdmin());

    foreach ([
        [['title' => ''], 'Title is required.'],
        [['title' => '   '], 'Title is required.'],
        [[], 'Title is required.'],
        [['title' => ['Ghost']], 'Title is required.'],
        [['title' => str_repeat('a', 256)], 'Title may not be longer than 255 characters.'],
    ] as [$body, $words]) {
        $this->putJson("/builder/assets/{$this->platform->id}", $body)->assertUnprocessable()->assertJsonPath('errors.title.0', $words);
    }

    $this->putJson('/builder/assets/999999', ['title' => 'Gone'])->assertNotFound();
    $this->putJson('/builder/assets/99999999999999999999', ['title' => 'Huge'])->assertNotFound();
    expect($this->platform->fresh()->title)->toBe('GAMA - ghost-03 (product)');
});
