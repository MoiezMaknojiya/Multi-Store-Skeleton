<?php

use App\Models\BuilderAd;
use App\Models\BuilderAsset;
use App\Models\Organization;
use App\Services\AdPublisher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Every organization's files its own: what was there before (owner, 2026-10-07)
|--------------------------------------------------------------------------
|
| `2026_10_07_200100_give_every_organization_its_own_copy_of_the_platform_files_its_ads_use`: from the Premium Templates on,
| an organization's designs use its own shelf alone, so an organization's ad that named one of the platform's files gets the
| organization's own copy of it — one copy per file per organization however many of its ads name it — its draft and its
| published version pointing at the copies, its own files left as they are, and a published page written again so it
| shows them. `down()` puts it all back.
|
*/

function ownFilesMigration(): object
{
    return require database_path('migrations/2026_10_07_200100_give_every_organization_its_own_copy_of_the_platform_files_its_ads_use.php');
}

/** A shelf file with real bytes behind it and its preview. */
function shelfFile(?int $organizationId, string $title): BuilderAsset
{
    $folder = 'builder/'.($organizationId ?? 'platform').'/assets';
    $name = strtolower(str_replace(' ', '-', $title));
    $asset = BuilderAsset::factory()->create([
        'organization_id' => $organizationId, 'title' => $title, 'size' => 3000,
        'path' => "{$folder}/{$name}.webp", 'thumbnail_path' => "{$folder}/thumbs/{$name}.jpg",
    ]);
    Storage::disk('public')->put($asset->path, "bytes of {$title}");
    Storage::disk('public')->put($asset->thumbnail_path, "preview of {$title}");

    return $asset;
}

/** A design with a picture of each file, the last one also behind it as a background layer. */
function designNaming(array $ids): array
{
    $document = BuilderAd::blankDocument();

    foreach ($ids as $i => $id) {
        $document['elements'][] = ['id' => "pic{$i}", 'type' => 'image', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 100, 'z' => $i, 'assetId' => $id];
    }

    $document['stage']['background']['layers'] = [['id' => 'bg', 'type' => 'image', 'assetId' => end($ids)]];

    return $document;
}

beforeEach(function () {
    Storage::fake('public');

    $this->alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
    $this->beta = Organization::factory()->create(['name' => 'Beta Deli']);

    $this->logo = shelfFile(null, 'Brand logo');
    $this->burger = shelfFile(null, 'Burger photo');
    $this->unused = shelfFile(null, 'Nobody uses me');
    $this->alphaOwn = shelfFile($this->alpha->id, 'Alpha own photo');

    // Alpha: a draft naming two platform files and its own, and a published ad naming the logo in both versions.
    $this->draft = BuilderAd::factory()->create([
        'organization_id' => $this->alpha->id, 'name' => 'Alpha draft',
        'document' => designNaming([$this->logo->id, $this->alphaOwn->id, $this->burger->id]),
    ]);
    $this->published = BuilderAd::factory()->create([
        'organization_id' => $this->alpha->id, 'name' => 'Alpha live', 'document' => designNaming([$this->logo->id]),
    ]);
    app(AdPublisher::class)->publish($this->published);
    $this->published->refresh();
    $this->stampBefore = $this->published->updated_at?->toIso8601String();
    // Its page as the compiler wrote it before the day: showing the platform's file.
    Storage::disk('public')->put($this->published->media->path, '<html><img src="/storage/'.$this->logo->path.'"></html>');

    // Beta names the logo too; the platform's own ad keeps the platform's file.
    $this->betaAd = BuilderAd::factory()->create(['organization_id' => $this->beta->id, 'name' => 'Beta ad', 'document' => designNaming([$this->logo->id])]);
    $this->platformAd = BuilderAd::factory()->create(['organization_id' => null, 'name' => 'Platform ad', 'document' => designNaming([$this->logo->id])]);
});

test('every organization\'s ad that named a platform file points at the organization\'s own copy, made once per file', function () {
    $page = Storage::disk('public')->get($this->published->media->path);
    expect($page)->toContain('brand-logo.webp');

    ownFilesMigration()->up();

    $alphaCopies = BuilderAsset::where('organization_id', $this->alpha->id)->whereNotNull('copied_from_id')->get()->keyBy('copied_from_id');
    $betaCopies = BuilderAsset::where('organization_id', $this->beta->id)->get()->keyBy('copied_from_id');

    // One copy per file per organization: Alpha's two ads share one copy of the logo, Beta has its own, nobody copied the unused file.
    expect($alphaCopies->keys()->sort()->values()->all())->toBe(collect([$this->logo->id, $this->burger->id])->sort()->values()->all())
        ->and($betaCopies->keys()->all())->toBe([$this->logo->id])
        ->and(BuilderAsset::where('copied_from_id', $this->unused->id)->count())->toBe(0);

    foreach ([...$alphaCopies->values(), ...$betaCopies->values()] as $copy) {
        $source = BuilderAsset::find($copy->copied_from_id);
        expect($copy->path)->toStartWith("builder/{$copy->organization_id}/assets/")
            ->and(Storage::disk('public')->get($copy->path))->toBe(Storage::disk('public')->get($source->path))
            ->and(Storage::disk('public')->get($copy->thumbnail_path))->toBe(Storage::disk('public')->get($source->thumbnail_path))
            ->and($copy->only(['title', 'kind', 'size', 'width', 'height']))->toBe($source->only(['title', 'kind', 'size', 'width', 'height']));
    }

    $logo = $alphaCopies[$this->logo->id]->id;
    $burger = $alphaCopies[$this->burger->id]->id;

    // The draft: the platform files swapped, its own file kept — in its elements and its background alike.
    expect(collect($this->draft->fresh()->document['elements'])->pluck('assetId')->all())->toBe([$logo, $this->alphaOwn->id, $burger])
        ->and($this->draft->fresh()->document['stage']['background']['layers'][0]['assetId'])->toBe($burger);

    // The published ad: both versions, its clock untouched (still published, not "changed"), its page written again.
    $live = $this->published->fresh();
    expect(BuilderAd::assetIdsIn($live->document))->toBe([$logo])
        ->and(BuilderAd::assetIdsIn($live->published_document))->toBe([$logo])
        ->and($live->updated_at?->toIso8601String())->toBe($this->stampBefore)
        ->and($live->status())->toBe('published');

    $page = Storage::disk('public')->get($live->media->path);
    expect($page)->toContain(basename($alphaCopies[$this->logo->id]->path))->not->toContain('brand-logo.webp');

    // Beta's ad, its own copy; the platform's own ad, the platform's file.
    expect(BuilderAd::assetIdsIn($this->betaAd->fresh()->document))->toBe([$betaCopies[$this->logo->id]->id])
        ->and(BuilderAd::assetIdsIn($this->platformAd->fresh()->document))->toBe([$this->logo->id]);

    // Run again, it finds nothing left to do.
    $rows = BuilderAsset::count();
    ownFilesMigration()->up();
    expect(BuilderAsset::count())->toBe($rows);
});

test('down() points the designs back at the platform\'s files and takes the copies away', function () {
    $draftBefore = $this->draft->fresh()->document;
    $publishedBefore = $this->published->fresh()->published_document;

    ownFilesMigration()->up();
    $copies = BuilderAsset::whereNotNull('copied_from_id')->get();
    ownFilesMigration()->down();

    expect($this->draft->fresh()->document)->toEqual($draftBefore)
        ->and($this->published->fresh()->published_document)->toEqual($publishedBefore)
        ->and(BuilderAd::assetIdsIn($this->betaAd->fresh()->document))->toBe([$this->logo->id])
        ->and(BuilderAsset::whereNotNull('copied_from_id')->count())->toBe(0)
        ->and(BuilderAsset::find($this->alphaOwn->id))->not->toBeNull();

    foreach ($copies as $copy) {
        Storage::disk('public')->assertMissing([$copy->path, $copy->thumbnail_path]);
    }
});

test('a database with no platform file, or no organization ad naming one, is left exactly as it was', function () {
    DB::table('builder_ads')->whereNotNull('organization_id')->update(['document' => json_encode(BuilderAd::blankDocument()), 'published_document' => null]);
    $before = [DB::table('builder_assets')->get()->toArray(), DB::table('builder_ads')->get()->toArray()];

    ownFilesMigration()->up();

    expect([DB::table('builder_assets')->get()->toArray(), DB::table('builder_ads')->get()->toArray()])->toEqual($before);
});
