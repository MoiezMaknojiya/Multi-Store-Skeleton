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
| The Ad Builder's shelf, shared with every organization (owner, 2026-09-29)
|--------------------------------------------------------------------------
|
| "Mujhe sub store k liya upload karna ho toh takay woo mere asset ko use kar sake." Above the organizations, an upload with
| no organization chosen is the platform's, shared with every organization: every organization's designers see it on their shelf and in their
| editor and may use it in their ads; it counts to no organization's storage; and it is deleted above the organizations alone (owner,
| 2026-10-01: an organization "srif delete nahi kar sakta ha") — from every organization at once, never while any organization's ad uses it,
| and without one organization ever learning the names of another's ads.
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

/** A file the platform shares with every organization, uploaded the way the Assets page does with no organization chosen. */
function shareAFile(TestCase $test, string $name = 'brand-logo.png'): BuilderAsset
{
    $test->actingAs(createSuperAdmin())->withSession([]);
    $test->postJson('/builder/assets', ['file' => UploadedFile::fake()->image($name, 400, 300)])->assertOk()->assertJsonPath('storage', null);

    return BuilderAsset::whereNull('organization_id')->latest('id')->firstOrFail();
}

test('an upload above the organizations with no organization chosen is shared with every organization, and counts to none', function () {
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

test('the chunked uploader shares a file too when no organization is chosen', function () {
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

test('every organization sees a shared file on its shelf and in its editor, marked as the platform\'s', function () {
    $shared = shareAFile($this);
    $own = BuilderAsset::factory()->create(['organization_id' => $this->organization->id, 'title' => 'Our logo']);

    foreach ([$this->organization, $this->other] as $organization) {
        $person = createOrganizationUser($organization, ['ad-view', 'ad-store', 'ad-update', 'ad-destroy'], 'Designer '.$organization->id);
        $this->actingAs($person)->withSession(['current_organization_id' => $organization->id]);

        $row = collect($this->getJson('/builder/assets/data')->assertOk()->json('assets'))->firstWhere('id', $shared->id);

        expect($row)->not->toBeNull()
            ->and($row['shared'])->toBeTrue()
            ->and($row['owner_label'])->toBe('From the platform')
            // Deleting a shared file is a permission of its own: Delete Ads is not it.
            ->and($row['can_delete'])->toBeFalse();

        $this->get('/builder/create?orientation=landscape')->assertOk()
            ->assertViewHas('assets', fn (array $assets) => collect($assets)->pluck('id')->contains($shared->id));
    }

    // …and an organization's own file stays its own.
    $this->actingAs(createOrganizationUser($this->other, ['ad-view'], 'Viewer'))->withSession(['current_organization_id' => $this->other->id]);
    expect(collect($this->getJson('/builder/assets/data')->json('assets'))->pluck('id')->all())->not->toContain($own->id);
});

test('an organization\'s ad uses a shared file, and its published page shows it', function () {
    $shared = shareAFile($this);

    $this->actingAs($this->designer)->withSession(['current_organization_id' => $this->organization->id]);

    $id = $this->postJson('/builder', ['name' => 'Brand week', 'document' => documentWithPicture($shared->id)])->assertOk()->json('ad.id');
    $this->postJson("/builder/{$id}/publish")->assertOk();

    expect(Storage::disk('public')->get(Media::sole()->path))->toContain(basename($shared->path));
});

test('a shared file is deleted above the organizations alone — never by an organization, never while an ad of any organization uses it', function () {
    $shared = shareAFile($this);

    BuilderAd::factory()->create(['organization_id' => $this->other->id, 'name' => 'Beta secret campaign', 'document' => documentWithPicture($shared->id)]);

    // An organization's people see it and use it, and never delete it, whatever their role holds (owner, 2026-10-01). Its
    // listing counts another organization's ad that uses it, never naming it.
    $this->actingAs($this->designer)->withSession(['current_organization_id' => $this->organization->id]);

    $row = collect($this->getJson('/builder/assets/data')->json('assets'))->firstWhere('id', $shared->id);
    expect($row['can_delete'])->toBeFalse()->and($row['used_by'])->toBe([])->and($row['used_elsewhere'])->toBe(1)
        ->and(json_encode($row))->not->toContain('Beta secret campaign');

    $this->deleteJson("/builder/assets/{$shared->id}")->assertForbidden()
        ->assertJsonPath('message', 'A file shared with every organization is the platform\'s: only the platform deletes it.');

    // Above the organizations it waits for every organization's ad to let it go.
    $this->actingAs(createSuperAdmin());
    $this->flushSession();
    $this->deleteJson("/builder/assets/{$shared->id}")->assertStatus(422);

    BuilderAd::query()->delete();

    $this->deleteJson("/builder/assets/{$shared->id}")->assertOk();

    expect(BuilderAsset::find($shared->id))->toBeNull();
    Storage::disk('public')->assertMissing($shared->path);
    expect(ActivityLog::where('action', 'ad_asset.deleted')->sole()->organization_id)->toBeNull();
});

test('Delete Ads deletes an organization\'s own file, and a shared one only above the organizations', function () {
    $shared = shareAFile($this);
    $own = BuilderAsset::factory()->create(['organization_id' => $this->organization->id, 'title' => 'Our logo']);

    // Inside the organization: its own file, never the shared one.
    $this->actingAs($this->designer)->withSession(['current_organization_id' => $this->organization->id]);
    $this->deleteJson("/builder/assets/{$shared->id}")->assertForbidden();
    $this->deleteJson("/builder/assets/{$own->id}")->assertOk();

    // Without Delete Ads the route itself says no.
    $this->actingAs(createOrganizationUser($this->organization, ['ad-view'], 'Viewer'))->withSession(['current_organization_id' => $this->organization->id]);
    $this->deleteJson("/builder/assets/{$shared->id}")->assertForbidden();

    // Above the organizations a platform role holding Delete Ads takes the shared file off every organization's shelf.
    $this->actingAs(createPlatformUser(['ad-view', 'ad-destroy'], 'Platform shelf keeper'));
    $this->flushSession();
    $this->deleteJson("/builder/assets/{$shared->id}")->assertOk();

    expect(BuilderAsset::whereKey([$shared->id, $own->id])->count())->toBe(0);
});

test('above the organizations the Organization list shows everything, what is shared, or one organization with the shared files', function () {
    $shared = shareAFile($this);
    $alpha = BuilderAsset::factory()->create(['organization_id' => $this->organization->id, 'title' => 'Alpha logo']);
    $beta = BuilderAsset::factory()->create(['organization_id' => $this->other->id, 'title' => 'Beta logo']);

    $ids = fn (string $query) => collect($this->getJson('/builder/assets/data'.$query)->assertOk()->json('assets'))->pluck('id')->sort()->values()->all();

    expect($ids(''))->toBe(collect([$shared->id, $alpha->id, $beta->id])->sort()->values()->all())
        ->and($ids('?organization_id='.$this->other->id))->toBe(collect([$shared->id, $beta->id])->sort()->values()->all());

    $labels = collect($this->getJson('/builder/assets/data')->json('assets'))->pluck('owner_label', 'id');
    expect($labels[$shared->id])->toBe('Every organization')->and($labels[$beta->id])->toBe('Beta Deli');

    // All organizations is the one option for every organization: there is no "shared" to ask for besides.
    $this->getJson('/builder/assets/data?organization_id=nope')->assertStatus(422);
    $this->getJson('/builder/assets/data?organization_id=shared')->assertStatus(422);
});

test('above the organizations a shared file in use says which organizations\' ads use it, and stays', function () {
    $shared = shareAFile($this);

    BuilderAd::factory()->create(['organization_id' => $this->other->id, 'name' => 'Beta week', 'document' => documentWithPicture($shared->id)]);

    // shareAFile left the super admin signed in.
    $row = collect($this->getJson('/builder/assets/data')->assertOk()->json('assets'))->firstWhere('id', $shared->id);

    expect($row['used_by'])->toBe(['Beta week (Beta Deli)'])
        ->and($row['used_elsewhere'])->toBe(0)
        ->and($row['can_delete'])->toBeTrue();

    expect($this->deleteJson("/builder/assets/{$shared->id}")->assertStatus(422)->json('errors.title.0'))
        ->toBe('Still used by Beta week (Beta Deli). Take it out of those ads first, and publish the ones whose screens still show it.');
});

test('a deleted organization takes its own files and leaves the shared ones', function () {
    $shared = shareAFile($this);

    $this->organization->delete();

    expect(BuilderAsset::find($shared->id))->not->toBeNull();
    Storage::disk('public')->assertExists($shared->path);
});
