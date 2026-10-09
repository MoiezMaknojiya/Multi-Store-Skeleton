<?php

use App\Models\ActivityLog;
use App\Models\BuilderAd;
use App\Models\BuilderAsset;
use App\Models\Media;
use App\Models\Organization;
use App\Models\Upload;
use App\Services\OrganizationStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Tus;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| The platform's own files, and every organization's own shelf
|--------------------------------------------------------------------------
|
| Owner, 2026-09-29: above the organizations an upload with no organization chosen is the platform's; it counts to no
| organization's storage and is deleted above the organizations alone (2026-10-01). Owner, 2026-10-07: "ab asset mein sare asset
| nahi dikhen gay. bs jo organization upload karega ya phir us ne jo preminum template se copy karen hongay" — the platform's
| files are its ads' for every organization (the Premium Templates) alone; an organization's shelf, its editor and its pages are
| its own files alone, a template's copied in with it (PremiumTemplatesTest).
|
*/

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('uploads');

    $this->organization = Organization::factory()->create(['name' => 'Alpha Mart']);
    $this->other = Organization::factory()->create(['name' => 'Beta Deli']);
    $this->designer = createOrganizationUser($this->organization, ['ad-view', 'ad-store', 'ad-update', 'ad-destroy'], 'Designer');
});

/** A document with one picture element naming $assetId. */
function documentWithPicture(int $assetId): array
{
    $document = BuilderAd::blankDocument();
    $document['elements'] = [[
        'id' => 'el_1', 'type' => 'image', 'x' => 0, 'y' => 0, 'w' => 400, 'h' => 300,
        'assetId' => $assetId, 'style' => [], 'animations' => [],
    ]];

    return $document;
}

/** A file of the platform's own, uploaded the way the Assets page does with no organization chosen. */
function shareAFile(TestCase $test, string $name = 'brand-logo.png'): BuilderAsset
{
    $test->actingAs(createSuperAdmin())->withSession([]);
    $test->postJson('/builder/assets', ['file' => UploadedFile::fake()->image($name, 400, 300)])->assertOk()->assertJsonPath('storage', null);

    return BuilderAsset::whereNull('organization_id')->latest('id')->firstOrFail();
}

test('an upload above the organizations with no organization chosen is the platform\'s, and counts to no organization', function () {
    $before = app(OrganizationStorage::class)->summary($this->organization->id)['used'];

    $asset = shareAFile($this);

    expect($asset->organization_id)->toBeNull()
        ->and($asset->isShared())->toBeTrue()
        ->and($asset->path)->toStartWith('builder/platform/assets/')
        ->and(app(OrganizationStorage::class)->summary($this->organization->id)['used'])->toBe($before);

    Storage::disk('public')->assertExists($asset->path);

    $entry = ActivityLog::where('action', 'ad_asset.uploaded')->sole();
    expect($entry->organization_id)->toBeNull()->and($entry->description)->toContain('shared with every organization');
});

test('the chunked uploader gives the platform a file too when no organization is chosen', function () {
    $this->actingAs(createSuperAdmin())->withSession([]);

    // As the page sends it: every field, the organization's empty (no organization chosen).
    $image = UploadedFile::fake()->image('banner.png', 200, 100);
    $id = Tus::upload($this, (string) file_get_contents($image->getRealPath()), [
        'name' => 'banner.png', 'type' => 'image/png', 'purpose' => 'asset', 'library' => '', 'organization' => '', 'channel' => '',
    ]);

    expect(Upload::find($id)->organization_id)->toBeNull();

    $this->postJson('/builder/assets', ['upload' => $id])->assertOk();

    expect(BuilderAsset::whereNull('organization_id')->sole()->title)->toBe('banner');
});

test('an organization\'s shelf and editor are its own files alone: the platform\'s are never listed or offered', function () {
    $platform = shareAFile($this);
    $own = BuilderAsset::factory()->create(['organization_id' => $this->organization->id, 'title' => 'Our logo']);
    $theirs = BuilderAsset::factory()->create(['organization_id' => $this->other->id, 'title' => 'Their logo']);

    $this->actingAs($this->designer)->withSession(['current_organization_id' => $this->organization->id]);

    $rows = collect($this->getJson('/builder/assets/data')->assertOk()->json('assets'));
    expect($rows->pluck('id')->all())->toBe([$own->id])
        ->and($rows->first())->toMatchArray(['shared' => false, 'owner_label' => null, 'can_delete' => true, 'used_by' => []])
        ->and($rows->first())->not->toHaveKeys(['used_elsewhere', 'used_by_platform']);

    $this->get('/builder/create?orientation=landscape')->assertOk()
        ->assertViewHas('assets', fn (array $assets) => collect($assets)->pluck('id')->all() === [$own->id]);

    $ad = BuilderAd::factory()->create(['organization_id' => $this->organization->id, 'document' => documentWithPicture($own->id)]);
    $this->get("/builder/{$ad->id}")->assertOk()
        ->assertViewHas('assets', fn (array $assets) => collect($assets)->pluck('id')->all() === [$own->id]);

    expect([$platform->id, $theirs->id])->each->not->toBeIn($rows->pluck('id')->all());
});

test('an organization\'s ad that names the platform\'s file by hand shows nothing of it on its page', function () {
    $platform = shareAFile($this);

    $this->actingAs($this->designer)->withSession(['current_organization_id' => $this->organization->id]);

    $id = $this->postJson('/builder', ['name' => 'Brand week', 'document' => documentWithPicture($platform->id)])->assertOk()->json('ad.id');
    $this->postJson("/builder/{$id}/publish")->assertOk();

    expect(Storage::disk('public')->get(Media::sole()->path))->not->toContain(basename($platform->path));
    $this->get("/builder/{$id}/preview")->assertOk()->assertDontSee(basename($platform->path), false);
});

test('the platform\'s file is deleted above the organizations alone, once no platform ad uses it; an organization cannot reach it', function () {
    $platform = shareAFile($this);
    $ad = BuilderAd::factory()->create(['organization_id' => null, 'name' => 'Platform week', 'document' => documentWithPicture($platform->id)]);

    $this->actingAs($this->designer)->withSession(['current_organization_id' => $this->organization->id]);
    $this->deleteJson("/builder/assets/{$platform->id}")->assertNotFound();

    $this->actingAs(createSuperAdmin());
    $this->flushSession();
    $this->deleteJson("/builder/assets/{$platform->id}")->assertStatus(422)
        ->assertJsonValidationErrors(['title' => 'Still used by Platform week. Take it out of those ads first, and publish the ones whose screens still show it.']);

    $ad->delete();
    $this->deleteJson("/builder/assets/{$platform->id}")->assertOk();

    expect(BuilderAsset::find($platform->id))->toBeNull();
    Storage::disk('public')->assertMissing($platform->path);
    expect(ActivityLog::where('action', 'ad_asset.deleted')->sole())
        ->organization_id->toBeNull()
        ->description->toBe("Deleted {$platform->title}, the platform's, from the ad builder");
});

test('Delete Ads deletes an organization\'s own file inside it, and the platform\'s only above the organizations', function () {
    $platform = shareAFile($this);
    $own = BuilderAsset::factory()->create(['organization_id' => $this->organization->id, 'title' => 'Our logo']);

    $this->actingAs($this->designer)->withSession(['current_organization_id' => $this->organization->id]);
    $this->deleteJson("/builder/assets/{$own->id}")->assertOk();

    // Without Delete Ads the route itself says no.
    $this->actingAs(createOrganizationUser($this->organization, ['ad-view'], 'Viewer'))->withSession(['current_organization_id' => $this->organization->id]);
    $this->deleteJson("/builder/assets/{$platform->id}")->assertForbidden();

    // Above the organizations a platform role holding Delete Ads takes the platform's file.
    $this->actingAs(createPlatformUser(['ad-view', 'ad-destroy'], 'Platform shelf keeper'));
    $this->flushSession();
    $this->deleteJson("/builder/assets/{$platform->id}")->assertOk();

    expect(BuilderAsset::whereKey([$platform->id, $own->id])->count())->toBe(0);
});

test('above the organizations the Owner list shows the platform\'s files, everything, or one organization\'s own files', function () {
    $platform = shareAFile($this);
    $alpha = BuilderAsset::factory()->create(['organization_id' => $this->organization->id, 'title' => 'Alpha logo']);
    $beta = BuilderAsset::factory()->create(['organization_id' => $this->other->id, 'title' => 'Beta logo']);

    $ids = fn (string $query) => collect($this->getJson('/builder/assets/data'.$query)->assertOk()->json('assets'))->pluck('id')->sort()->values()->all();

    expect($ids(''))->toBe(collect([$platform->id, $alpha->id, $beta->id])->sort()->values()->all())
        ->and($ids('?organization_id=platform'))->toBe([$platform->id])
        ->and($ids('?organization_id='.$this->other->id))->toBe([$beta->id]);

    // Every file says whose it is (owner, 2026-10-08), and the platform's files have no wall to meter.
    $labels = collect($this->getJson('/builder/assets/data')->json('assets'))->pluck('owner_label', 'id');
    expect($labels[$platform->id])->toBe('Platform')->and($labels[$beta->id])->toBe('Beta Deli')
        ->and($this->getJson('/builder/assets/data?organization_id=platform')->json('storage'))->toBeNull();

    // Platform is the one word for the platform's files: nothing else that is no organization's id is asked for.
    foreach (['nope', 'shared', '0', 'Platform', '-1', '1.5'] as $value) {
        $this->getJson('/builder/assets/data?organization_id='.urlencode($value))->assertStatus(422);
    }
    $this->getJson('/builder/assets/data?organization_id[]=platform')->assertStatus(422);
});

test('above the organizations an organization\'s file in use says which of its ads use it, and stays', function () {
    $beta = BuilderAsset::factory()->create(['organization_id' => $this->other->id, 'title' => 'Beta logo']);
    BuilderAd::factory()->create(['organization_id' => $this->other->id, 'name' => 'Beta week', 'document' => documentWithPicture($beta->id)]);

    $this->actingAs(createSuperAdmin());

    $row = collect($this->getJson('/builder/assets/data')->assertOk()->json('assets'))->firstWhere('id', $beta->id);

    expect($row['used_by'])->toBe(['Beta week'])->and($row['can_delete'])->toBeTrue();

    expect($this->deleteJson("/builder/assets/{$beta->id}")->assertStatus(422)->json('errors.title.0'))
        ->toBe('Still used by Beta week. Take it out of those ads first, and publish the ones whose screens still show it.');
});

test('a deleted organization takes its own files and leaves the platform\'s', function () {
    $platform = shareAFile($this);

    $this->organization->delete();

    expect(BuilderAsset::find($platform->id))->not->toBeNull();
    Storage::disk('public')->assertExists($platform->path);
});
