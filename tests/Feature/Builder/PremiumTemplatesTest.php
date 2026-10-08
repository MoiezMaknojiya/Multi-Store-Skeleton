<?php

use App\Models\ActivityLog;
use App\Models\BuilderAd;
use App\Models\BuilderAsset;
use App\Models\Media;
use App\Models\Organization;
use App\Services\OrganizationStorage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Premium Templates (owner, 2026-10-07)
|--------------------------------------------------------------------------
|
| "Jab orientation select kare us k bad usko two option show ho "Create You Own" Ya Phir "Preminum template" preminum
| template mein sare all organization walay template ajaye. Jab Woo Preminum template se select kare toh uski copy ban jaye
| aur srif ushi k asset dikhe ... agar subscription khatam bhi ho jati ha toh woo select tempate uska ho gaya chalta rahe aur
| agar super admin ... woo template delete ya depreciate kare toh super admin wala delete ho jaye assest aur organization wala
| rahe." The gallery is the platform's published ads for every organization of one shape; Use This Template makes one the
| organization's own, with its own copy of every file it names (TemplateCopier).
|
*/

beforeEach(function () {
    Storage::fake('public');

    $this->organization = Organization::factory()->create(['name' => 'Alpha Mart']);
    $this->other = Organization::factory()->create(['name' => 'Beta Deli']);
    $this->designer = createOrganizationUser($this->organization, ['ad-view', 'ad-store', 'ad-update', 'ad-destroy'], 'Designer');

    $this->actingAs($this->designer)->withSession(['current_organization_id' => $this->organization->id]);
});

/** A platform file with real bytes on the disk, and its preview. */
function templateFile(string $title = 'Brand logo', int $bytes = 4096): BuilderAsset
{
    $name = Str::lower(Str::random(10));
    $asset = BuilderAsset::factory()->create([
        'organization_id' => null, 'title' => $title, 'size' => $bytes,
        'path' => "builder/platform/assets/{$name}.webp", 'thumbnail_path' => "builder/platform/assets/thumbs/{$name}.jpg",
    ]);

    Storage::disk('public')->put($asset->path, str_repeat('p', $bytes));
    Storage::disk('public')->put($asset->thumbnail_path, str_repeat('t', 100));

    return $asset;
}

/** A design naming these files: each as a picture on the stage, the last one also as a background layer. */
function templateDocument(string $text, array $assetIds, string $orientation = BuilderAd::LANDSCAPE): array
{
    $document = BuilderAd::blankDocument($orientation);
    $document['elements'][] = [
        'id' => 'el_text', 'type' => 'text', 'name' => 'Headline', 'x' => 10, 'y' => 10, 'w' => 600, 'h' => 120,
        'rotation' => 0, 'opacity' => 1, 'z' => 0, 'locked' => false, 'visible' => true,
        'text' => $text, 'style' => ['fontSize' => 72, 'color' => '#ffffff'], 'animations' => [],
    ];

    foreach (array_values($assetIds) as $i => $id) {
        $document['elements'][] = [
            'id' => "el_pic_{$i}", 'type' => 'image', 'name' => "Picture {$i}", 'x' => 0, 'y' => 200, 'w' => 400, 'h' => 300,
            'rotation' => 0, 'opacity' => 1, 'z' => $i + 1, 'locked' => false, 'visible' => true,
            'assetId' => $id, 'style' => [], 'animations' => [],
        ];
    }

    if ($assetIds !== []) {
        $document['stage']['background']['layers'] = [['id' => 'layer_1', 'type' => 'image', 'assetId' => end($assetIds)]];
    }

    return $document;
}

/** A published Premium Template, its poster on the disk. */
function premiumTemplate(string $name, array $assetIds = [], string $orientation = BuilderAd::LANDSCAPE): BuilderAd
{
    $factory = BuilderAd::factory();
    $template = ($orientation === BuilderAd::PORTRAIT ? $factory->portrait() : $factory)
        ->state(['organization_id' => null, 'name' => $name, 'orientation' => $orientation, 'document' => templateDocument($name, $assetIds, $orientation)])
        ->published()
        ->create();

    $template->media->update(['thumbnail_path' => "builder/platform/ads/{$template->id}/published.jpg"]);
    Storage::disk('public')->put($template->media->thumbnail_path, str_repeat('j', 500));

    return $template->fresh();
}

/** The gallery's names, by id, in the order it lists them. */
function templatesListed(TestCase $test, string $query = 'orientation=landscape'): array
{
    return collect($test->getJson('/builder/templates?'.$query)->assertOk()->json('templates'))->pluck('name', 'id')->all();
}

/* ── The gallery ─────────────────────────────────────────────────────────── */

test('the gallery lists the platform\'s published templates of the shape chosen, as they were published, newest first', function () {
    $older = premiumTemplate('Burger menu');
    $older->forceFill(['published_at' => now()->subDay()])->save();
    $newer = premiumTemplate('Coffee menu');
    $portrait = premiumTemplate('Upright menu', orientation: BuilderAd::PORTRAIT);

    // Changed above the organizations since it was published: the gallery says the published name, never the draft's.
    BuilderAd::whereKey($newer->id)->update(['name' => 'Coffee menu (half done)']);

    BuilderAd::factory()->create(['organization_id' => null, 'name' => 'Draft menu']);
    BuilderAd::factory()->published()->create(['organization_id' => null, 'name' => 'Unpublished menu'])->forceFill(['published_at' => null])->save();
    BuilderAd::factory()->published()->create(['organization_id' => $this->organization->id, 'name' => 'Our own menu']);
    BuilderAd::factory()->published()->create(['organization_id' => $this->other->id, 'name' => 'Beta menu']);

    expect(templatesListed($this))->toBe([$newer->id => 'Coffee menu', $older->id => 'Burger menu'])
        ->and(templatesListed($this, 'orientation=portrait'))->toBe([$portrait->id => 'Upright menu']);

    $row = collect($this->getJson('/builder/templates?orientation=landscape')->json('templates'))->firstWhere('id', $newer->id);
    expect($row)->toMatchArray(['orientation' => 'landscape', 'preview_url' => route('builder.preview', $newer)])
        ->and($row['thumbnail_url'])->toContain("builder/platform/ads/{$newer->id}/published.jpg")
        ->and(array_keys($row))->toBe(['id', 'name', 'orientation', 'thumbnail_url', 'preview_url']);

    // Searched by the name it was published under.
    expect(templatesListed($this, 'orientation=landscape&search=burger'))->toBe([$older->id => 'Burger menu'])
        ->and(templatesListed($this, 'orientation=landscape&search=half'))->toBe([]);
});

test('the gallery asks which way the screen is first', function () {
    $this->getJson('/builder/templates')->assertStatus(422)->assertJsonValidationErrors(['orientation' => 'Choose which way the screen is first.']);
    $this->getJson('/builder/templates?orientation=sideways')->assertStatus(422)->assertJsonValidationErrors(['orientation' => 'Choose which way the screen is first.']);
});

/* ── Use This Template ───────────────────────────────────────────────────── */

test('Use This Template makes the organization\'s own ad, with its own copy of every file the template names', function () {
    $logo = templateFile('Brand logo', 4096);
    $burger = templateFile('Burger photo', 8192);
    $template = premiumTemplate('Burger menu', [$logo->id, $burger->id]);
    $before = app(OrganizationStorage::class)->used($this->organization->id);

    $answer = $this->postJson("/builder/templates/{$template->id}")->assertOk()
        ->assertJsonPath('message', 'Template copied to your ads');
    $copy = BuilderAd::findOrFail($answer->json('ad.id'));

    expect($answer->json('redirect'))->toBe(route('builder.edit', $copy))
        ->and($copy->organization_id)->toBe($this->organization->id)
        ->and($copy->name)->toBe('Burger menu')
        ->and($copy->orientation)->toBe('landscape')
        ->and($copy->isPublished())->toBeFalse()
        ->and($copy->document['elements'][0]['text'])->toBe('Burger menu');

    // Its own files: rows of the organization saying where they came from, their bytes and previews copied beside its uploads.
    $copies = BuilderAsset::where('organization_id', $this->organization->id)->orderBy('id')->get();
    expect($copies->pluck('copied_from_id')->all())->toBe([$logo->id, $burger->id])
        ->and($copies->pluck('title')->all())->toBe(['Brand logo', 'Burger photo'])
        ->and(BuilderAd::assetIdsIn($copy->document))->toEqualCanonicalizing($copies->pluck('id')->all())
        ->and($copy->document['stage']['background']['layers'][0]['assetId'])->toBe($copies[1]->id);

    foreach ($copies as $i => $file) {
        $source = [$logo, $burger][$i];
        expect($file->path)->toStartWith("builder/{$this->organization->id}/assets/")
            ->and($file->thumbnail_path)->toStartWith("builder/{$this->organization->id}/assets/thumbs/")
            ->and(Storage::disk('public')->get($file->path))->toBe(Storage::disk('public')->get($source->path))
            ->and($file->size)->toBe($source->size);
    }

    // The poster too, and all of it counted to the organization's 512 MB.
    expect($copy->thumbnail_path)->toBe("builder/{$this->organization->id}/ads/{$copy->id}/poster.jpg")
        ->and(app(OrganizationStorage::class)->used($this->organization->id))->toBe($before + 4096 + 8192 + 100 + 100 + 500);
    Storage::disk('public')->assertExists($copy->thumbnail_path);

    expect(ActivityLog::where('action', 'ad.copied')->sole())
        ->organization_id->toBe($this->organization->id)
        ->description->toBe('Made ad Burger menu from the premium template Burger menu');

    // The editor offers the copy's own files, and nothing of the platform's.
    $this->get(route('builder.edit', $copy))->assertOk()
        ->assertViewHas('assets', fn (array $assets) => collect($assets)->pluck('id')->sort()->values()->all() === $copies->pluck('id')->all());
});

test('a file the organization already holds a copy of is used again, never copied twice', function () {
    $logo = templateFile('Brand logo');
    $menuA = premiumTemplate('Burger menu', [$logo->id]);
    $menuB = premiumTemplate('Coffee menu', [$logo->id, templateFile('Coffee photo')->id]);

    $first = BuilderAd::find($this->postJson("/builder/templates/{$menuA->id}")->assertOk()->json('ad.id'));
    $again = BuilderAd::find($this->postJson("/builder/templates/{$menuA->id}")->assertOk()->json('ad.id'));
    $other = BuilderAd::find($this->postJson("/builder/templates/{$menuB->id}")->assertOk()->json('ad.id'));

    $logoCopy = BuilderAsset::where('organization_id', $this->organization->id)->where('copied_from_id', $logo->id)->sole();

    expect($again->name)->toBe('Burger menu (copy)')
        ->and(BuilderAsset::where('organization_id', $this->organization->id)->count())->toBe(2)
        ->and(BuilderAd::assetIdsIn($first->document))->toBe([$logoCopy->id])
        ->and(BuilderAd::assetIdsIn($again->document))->toBe([$logoCopy->id])
        ->and(BuilderAd::assetIdsIn($other->document))->toContain($logoCopy->id);

    // Another organization gets copies of its own.
    $this->actingAs(createOrganizationUser($this->other, ['ad-store'], 'Beta designer'))->withSession(['current_organization_id' => $this->other->id]);
    $theirs = BuilderAd::find($this->postJson("/builder/templates/{$menuA->id}")->assertOk()->json('ad.id'));
    $theirLogo = BuilderAsset::where('organization_id', $this->other->id)->sole();

    expect($theirLogo->id)->not->toBe($logoCopy->id)
        ->and(BuilderAd::assetIdsIn($theirs->document))->toBe([$theirLogo->id]);
});

test('a copy whose file the organization deleted gets a new copy the next time', function () {
    $logo = templateFile('Brand logo');
    $template = premiumTemplate('Burger menu', [$logo->id]);

    $this->postJson("/builder/templates/{$template->id}")->assertOk();
    $held = BuilderAsset::where('organization_id', $this->organization->id)->sole();
    Storage::disk('public')->delete($held->path);

    $copy = BuilderAd::find($this->postJson("/builder/templates/{$template->id}")->assertOk()->json('ad.id'));
    $fresh = BuilderAsset::where('organization_id', $this->organization->id)->latest('id')->first();

    expect($fresh->id)->not->toBe($held->id)
        ->and(BuilderAd::assetIdsIn($copy->document))->toBe([$fresh->id]);
    Storage::disk('public')->assertExists($fresh->path);
});

test('a file the template names that is not the platform\'s never reaches the copy', function () {
    $platform = templateFile('Brand logo');
    $theirs = BuilderAsset::factory()->create(['organization_id' => $this->other->id, 'path' => 'builder/2/assets/secret.webp']);
    Storage::disk('public')->put($theirs->path, 'secret');

    $template = premiumTemplate('Burger menu', [$platform->id, $theirs->id, 999999]);

    $copy = BuilderAd::find($this->postJson("/builder/templates/{$template->id}")->assertOk()->json('ad.id'));
    $own = BuilderAsset::where('organization_id', $this->organization->id)->sole();

    expect(BuilderAd::assetIdsIn($copy->document))->toBe([$own->id])
        ->and($own->copied_from_id)->toBe($platform->id)
        ->and(collect($copy->document['elements'])->where('type', 'image')->pluck('assetId')->all())->toBe([$own->id, null, null])
        ->and($copy->document['stage']['background']['layers'][0]['assetId'])->toBeNull();
});

test('the platform deleting the template and its files leaves the organization\'s copy and its files as they are', function () {
    $logo = templateFile('Brand logo');
    $template = premiumTemplate('Burger menu', [$logo->id]);
    $copy = BuilderAd::find($this->postJson("/builder/templates/{$template->id}")->assertOk()->json('ad.id'));
    $own = BuilderAsset::where('organization_id', $this->organization->id)->sole();

    $this->actingAs(createSuperAdmin())->flushSession();
    $this->deleteJson("/builder/{$template->id}", ['password' => 'password'])->assertOk();
    $this->deleteJson("/builder/assets/{$logo->id}")->assertOk();

    expect(BuilderAd::find($copy->id))->not->toBeNull()
        ->and(BuilderAsset::find($own->id)?->copied_from_id)->toBeNull();
    Storage::disk('public')->assertExists([$own->path, $own->thumbnail_path, $copy->thumbnail_path]);
    Storage::disk('public')->assertMissing($logo->path);

    // And the copy still publishes with its picture, from its own shelf.
    $this->actingAs($this->designer)->withSession(['current_organization_id' => $this->organization->id]);
    $this->postJson("/builder/{$copy->id}/publish")->assertOk();

    expect(Storage::disk('public')->get($copy->fresh()->media->path))->toContain(basename($own->path))->not->toContain(basename($logo->path));
});

test('a deleted organization takes its copies with it, and the platform\'s files stay', function () {
    $logo = templateFile('Brand logo');
    $template = premiumTemplate('Burger menu', [$logo->id]);
    $this->postJson("/builder/templates/{$template->id}")->assertOk();
    $own = BuilderAsset::where('organization_id', $this->organization->id)->sole();

    $this->organization->delete();

    expect(BuilderAsset::find($own->id))->toBeNull()->and(BuilderAsset::find($logo->id))->not->toBeNull();
    Storage::disk('public')->assertMissing($own->path);
    Storage::disk('public')->assertExists($logo->path);
});

test('without room on the organization\'s 512 MB nothing is written, and the answer says how much is needed', function () {
    $logo = templateFile('Brand logo', 4096);
    $template = premiumTemplate('Burger menu', [$logo->id]);
    Media::factory()->create(['organization_id' => $this->organization->id, 'size' => OrganizationStorage::LIMIT_BYTES - 1000, 'thumbnail_path' => null]);
    $files = count(Storage::disk('public')->allFiles());

    // The file and its preview, 4196 bytes, said the way the meter says them.

    $this->postJson("/builder/templates/{$template->id}")->assertStatus(422)
        ->assertJsonValidationErrors(['template' => 'Not enough storage: this needs 5 KB, and Alpha Mart has 1 KB left of its 512 MB. Delete files you no longer use to make room.']);

    expect(BuilderAd::where('organization_id', $this->organization->id)->count())->toBe(0)
        ->and(BuilderAsset::where('organization_id', $this->organization->id)->count())->toBe(0)
        ->and(count(Storage::disk('public')->allFiles()))->toBe($files);
});

test('a template whose files the organization already holds needs no room for them: only the poster waits for room', function () {
    $logo = templateFile('Brand logo', 4096);
    $template = premiumTemplate('Burger menu', [$logo->id]);
    $this->postJson("/builder/templates/{$template->id}")->assertOk();

    Media::factory()->create(['organization_id' => $this->organization->id, 'size' => OrganizationStorage::LIMIT_BYTES - app(OrganizationStorage::class)->used($this->organization->id), 'thumbnail_path' => null]);

    $copy = BuilderAd::find($this->postJson("/builder/templates/{$template->id}")->assertOk()->json('ad.id'));

    expect($copy->thumbnail_path)->toBeNull()
        ->and(BuilderAsset::where('organization_id', $this->organization->id)->count())->toBe(1);
});

test('only an organization\'s person who may make an ad uses a template, and the platform has none to use', function () {
    $template = premiumTemplate('Burger menu');

    $this->actingAs(createOrganizationUser($this->organization, ['ad-view', 'ad-update'], 'Editor'))->withSession(['current_organization_id' => $this->organization->id]);
    $this->getJson('/builder/templates?orientation=landscape')->assertForbidden();
    $this->postJson("/builder/templates/{$template->id}")->assertForbidden();

    $this->actingAs(createSuperAdmin())->flushSession();
    $this->getJson('/builder/templates?orientation=landscape')->assertNotFound();
    $this->postJson("/builder/templates/{$template->id}")->assertNotFound();

    expect(BuilderAd::count())->toBe(1);
});
