<?php

use App\Models\BuilderAd;
use App\Models\BuilderAsset;
use App\Models\Media;
use App\Models\Organization;
use App\Services\OrganizationStorage;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Premium Templates, attacked (owner, 2026-10-07)
|--------------------------------------------------------------------------
|
| Use This Template writes files and rows into an organization, so every way of pointing it somewhere else is tried: ids
| that are no template, another organization's ad, the platform's side, a session that names an organization the person is
| not in, payloads saying more than the button sends, shapes nobody sends, and an organization using its last megabytes.
|
*/

beforeEach(function () {
    Storage::fake('public');

    $this->organization = Organization::factory()->create(['name' => 'Alpha Mart']);
    $this->other = Organization::factory()->create(['name' => 'Beta Deli']);
    $this->designer = createOrganizationUser($this->organization, ['ad-view', 'ad-store', 'ad-update', 'ad-destroy'], 'Designer');

    $this->actingAs($this->designer)->withSession(['current_organization_id' => $this->organization->id]);
});

/** The platform's published ad for every organization naming this file — with real bytes behind the file. */
function attackTemplate(string $name = 'Burger menu', ?BuilderAsset $file = null): BuilderAd
{
    $document = BuilderAd::blankDocument();
    $document['elements'] = $file === null ? [] : [[
        'id' => 'pic', 'type' => 'image', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 100, 'z' => 0, 'assetId' => $file->id,
    ]];

    return BuilderAd::factory()->state(['organization_id' => null, 'name' => $name, 'document' => $document])->published()->create();
}

function attackFile(?int $organizationId = null, int $bytes = 2048): BuilderAsset
{
    $asset = BuilderAsset::factory()->create([
        'organization_id' => $organizationId, 'size' => $bytes, 'thumbnail_path' => null,
        'path' => 'builder/'.($organizationId ?? 'platform').'/assets/'.uniqid().'.webp',
    ]);
    Storage::disk('public')->put($asset->path, str_repeat('x', $bytes));

    return $asset;
}

/** Nothing was made in the organization: no ad, no file. */
function nothingMadeIn(Organization $organization): void
{
    expect(BuilderAd::where('organization_id', $organization->id)->count())->toBe(0)
        ->and(BuilderAsset::where('organization_id', $organization->id)->count())->toBe(0);
}

test('an id that is no Premium Template answers 404 and makes nothing', function () {
    $draft = BuilderAd::factory()->create(['organization_id' => null, 'name' => 'Not yet']);
    $unpublished = attackTemplate('Taken off');
    $unpublished->forceFill(['published_at' => null])->save();
    $noVersionKept = attackTemplate('Old');
    $noVersionKept->forceFill(['published_document' => null])->save();
    $own = BuilderAd::factory()->published()->create(['organization_id' => $this->organization->id]);
    $theirs = BuilderAd::factory()->published()->create(['organization_id' => $this->other->id]);

    foreach ([$draft->id, $unpublished->id, $noVersionKept->id, $own->id, $theirs->id, 999999] as $id) {
        $this->postJson("/builder/templates/{$id}")->assertNotFound();
    }

    // Nor is any of them a template to preview (the organization's own ad previews as its own).
    foreach ([$draft->id, $unpublished->id, $noVersionKept->id, $theirs->id, 999999] as $id) {
        $this->get("/builder/{$id}/preview")->assertNotFound()->assertDontSee('Not yet');
    }

    // What is not a number is no route at all.
    foreach (['abc', '0x1', '1;DROP', '-1'] as $id) {
        $this->postJson("/builder/templates/{$id}")->assertNotFound();
    }

    expect(BuilderAd::where('organization_id', $this->organization->id)->count())->toBe(1)
        ->and(BuilderAsset::where('organization_id', $this->organization->id)->count())->toBe(0);
});

test('the payload cannot move the copy, rename it, or hand it another organization\'s file', function () {
    $file = attackFile();
    $theirs = attackFile($this->other->id);
    $template = attackTemplate('Burger menu', $file);

    $id = $this->postJson("/builder/templates/{$template->id}", [
        'organization_id' => $this->other->id, 'name' => '<script>x</script>', 'document' => ['elements' => [['assetId' => $theirs->id]]],
        'orientation' => 'portrait', 'published_at' => now(), 'media_id' => $template->media_id,
    ])->assertOk()->json('ad.id');

    $copy = BuilderAd::findOrFail($id);
    expect($copy->organization_id)->toBe($this->organization->id)
        ->and($copy->name)->toBe('Burger menu')
        ->and($copy->orientation)->toBe('landscape')
        ->and($copy->published_at)->toBeNull()
        ->and($copy->media_id)->toBeNull()
        ->and(BuilderAd::assetIdsIn($copy->document))->not->toContain($theirs->id)
        // Beta Deli keeps exactly its one file, and gets no ad.
        ->and(BuilderAsset::where('organization_id', $this->other->id)->pluck('id')->all())->toBe([$theirs->id])
        ->and(BuilderAd::where('organization_id', $this->other->id)->count())->toBe(0);
});

test('the platform\'s side, a session naming another organization, and a guest get nothing', function () {
    $template = attackTemplate('Burger menu', attackFile());

    // Above the organizations there is no Premium Template to use — even with an organization left in the session.
    $this->actingAs(createSuperAdmin())->withSession(['current_organization_id' => $this->organization->id]);
    $this->getJson('/builder/templates?orientation=landscape')->assertNotFound();
    $this->postJson("/builder/templates/{$template->id}")->assertNotFound();

    // A session naming an organization the person is not a member of.
    $this->actingAs($this->designer)->withSession(['current_organization_id' => $this->other->id]);
    $this->postJson("/builder/templates/{$template->id}")->assertStatus(403);

    // No organization at all.
    $this->actingAs($this->designer)->withSession(['current_organization_id' => null]);
    $this->postJson("/builder/templates/{$template->id}")->assertStatus(403);

    auth()->logout();
    $this->postJson("/builder/templates/{$template->id}")->assertUnauthorized();
    $this->getJson('/builder/templates?orientation=landscape')->assertUnauthorized();

    nothingMadeIn($this->organization);
    nothingMadeIn($this->other);
});

test('shapes nobody sends are refused in words, never a 500', function () {
    attackTemplate();

    $this->getJson('/builder/templates?orientation[]=landscape')->assertStatus(422)->assertJsonValidationErrors('orientation');
    $this->getJson('/builder/templates?orientation=landscape&search[]=x')->assertStatus(422)->assertJsonValidationErrors('search');
    $this->getJson('/builder/templates?orientation=landscape&search='.str_repeat('a', 256))->assertStatus(422)->assertJsonValidationErrors('search');
    $this->getJson('/builder/templates?orientation=landscape&search='.urlencode("' OR 1=1 --"))->assertOk()->assertJsonPath('templates', []);
    $this->getJson('/builder/templates?orientation=landscape&search=%25')->assertOk();

    $this->getJson('/builder/templates/1')->assertStatus(405);
});

test('an organization using its last megabytes: what fits is copied, what does not is refused whole, and its files go again', function () {
    $small = attackTemplate('Small menu', attackFile(null, 1024));
    $big = attackTemplate('Big menu', attackFile(null, 6000));

    // Room for the small one's file (1 KB) and no more.
    Media::factory()->create(['organization_id' => $this->organization->id, 'size' => OrganizationStorage::LIMIT_BYTES - 2000, 'thumbnail_path' => null]);

    $this->postJson("/builder/templates/{$small->id}")->assertOk();
    $files = Storage::disk('public')->allFiles();

    $this->postJson("/builder/templates/{$big->id}")->assertStatus(422)->assertJsonValidationErrors('template');

    expect(BuilderAd::where('organization_id', $this->organization->id)->count())->toBe(1)
        ->and(BuilderAsset::where('organization_id', $this->organization->id)->count())->toBe(1)
        ->and(Storage::disk('public')->allFiles())->toBe($files)
        ->and(app(OrganizationStorage::class)->used($this->organization->id))->toBeLessThanOrEqual(OrganizationStorage::LIMIT_BYTES);
});

test('a platform file whose bytes are gone is left out of the copy, never a 500', function () {
    $file = attackFile();
    $template = attackTemplate('Burger menu', $file);
    Storage::disk('public')->delete($file->path);

    $copy = BuilderAd::find($this->postJson("/builder/templates/{$template->id}")->assertOk()->json('ad.id'));

    expect(BuilderAd::assetIdsIn($copy->document))->toBe([]);
    nothingMadeIn($this->other);
});
