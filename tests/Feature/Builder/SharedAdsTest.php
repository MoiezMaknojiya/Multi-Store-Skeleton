<?php

use App\Models\ActivityLog;
use App\Models\BuilderAd;
use App\Models\BuilderAsset;
use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Media;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\User;
use App\Services\OrganizationStorage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Ads for every organization (owner, 2026-10-01)
|--------------------------------------------------------------------------
|
| "Mein all shop k liya ads kese banao? Jese asset mein ha woo ads sub ko dikhe aur woo copy kar sake." Above the
| organizations an ad with no organization chosen — All organizations — is the platform's, shared with every organization: it uses the shared files
| alone, publishes into the platform's own library, and every organization sees it once it is published ("publish ke baad") —
| as it was published, never its unfinished changes — and copies it into its own Ads. An organization's people see it, use it
| and copy it, and nothing more, whatever their role holds ("srif delete nahi kar sakta ha ... permission hata do"):
| only above the organizations is it changed (Update Ads) or deleted (Delete Ads).
|
*/

beforeEach(function () {
    Storage::fake('public');

    $this->organization = Organization::factory()->create(['name' => 'Alpha Mart']);
    $this->other = Organization::factory()->create(['name' => 'Beta Deli']);
    // An organization's designer: everything an organization's own ads need, nothing of the platform's.
    $this->designer = createOrganizationUser($this->organization, ['ad-view', 'ad-store', 'ad-update', 'ad-destroy'], 'Designer');
});

/** A design with one headline — and, given an asset, a picture of it. */
function everyOrganizationDocument(string $text = 'Winter sale', ?int $assetId = null): array
{
    $document = BuilderAd::blankDocument();
    $document['elements'] = [[
        'id' => 'el_1', 'type' => 'text', 'name' => 'Headline', 'x' => 160, 'y' => 240, 'w' => 1200, 'h' => 200,
        'rotation' => 0, 'opacity' => 1, 'z' => 0, 'locked' => false, 'visible' => true,
        'text' => $text, 'style' => ['fontSize' => 96, 'color' => '#ffffff'], 'animations' => [],
    ]];

    if ($assetId !== null) {
        $document['elements'][] = [
            'id' => 'el_2', 'type' => 'image', 'name' => 'Picture', 'x' => 0, 'y' => 0, 'w' => 400, 'h' => 300,
            'rotation' => 0, 'opacity' => 1, 'z' => 1, 'locked' => false, 'visible' => true,
            'assetId' => $assetId, 'style' => [], 'animations' => [],
        ];
    }

    return $document;
}

/** A poster the way the editor's canvas hands one over. */
function everyOrganizationPoster(): string
{
    $image = imagecreatetruecolor(320, 180);
    imagefilledrectangle($image, 0, 0, 320, 180, imagecolorallocate($image, 200, 40, 40));

    ob_start();
    imagejpeg($image, null, 85);

    return 'data:image/jpeg;base64,'.base64_encode((string) ob_get_clean());
}

/** A super admin makes an ad for every organization through the editor's own endpoints — and publishes it, unless told not to. */
function makeForEveryOrganization(TestCase $test, string $name = 'Winter sale', bool $publish = true): BuilderAd
{
    $test->actingAs(createSuperAdmin());
    $test->flushSession();

    $id = $test->postJson('/builder', ['name' => $name, 'document' => everyOrganizationDocument($name), 'thumbnail' => everyOrganizationPoster()])
        ->assertOk()->json('ad.id');

    if ($publish) {
        $test->postJson("/builder/{$id}/publish")->assertOk()->assertJsonPath('message', 'Published — every organization sees it now and can copy it');
    }

    return BuilderAd::findOrFail($id);
}

/** What an organization's person is shown on the Ads page, by id. */
function adsSeenIn(TestCase $test, User $person, Organization $organization): Collection
{
    return collect($test->actingAs($person)->withSession(['current_organization_id' => $organization->id])
        ->getJson('/builder/data')->assertOk()->json('ads'))->keyBy('id');
}

test('an ad for every organization is the platform\'s: no organization, its own folder, the platform\'s library, and no organization\'s storage', function () {
    $before = app(OrganizationStorage::class)->summary($this->organization->id)['used'];

    $ad = makeForEveryOrganization($this);

    expect($ad->organization_id)->toBeNull()
        ->and($ad->isShared())->toBeTrue()
        ->and($ad->thumbnail_path)->toBe("builder/platform/ads/{$ad->id}/poster.jpg")
        ->and($ad->media->organization_id)->toBeNull()
        ->and($ad->media->path)->toBe("builder/platform/ads/{$ad->id}/index.html")
        ->and(app(OrganizationStorage::class)->summary($this->organization->id)['used'])->toBe($before);

    Storage::disk('public')->assertExists([$ad->thumbnail_path, $ad->media->path, $ad->media->thumbnail_path]);

    $created = ActivityLog::where('action', 'ad.created')->sole();
    expect($created->organization_id)->toBeNull()
        ->and($created->description)->toBe('Created landscape ad Winter sale for every organization')
        ->and(ActivityLog::where('action', 'ad.published')->sole()->description)->toBe('Published ad Winter sale for every organization');

    // Above the organizations its card and its editor say whose it is.
    $row = collect($this->getJson('/builder/data')->assertOk()->json('ads'))->firstWhere('id', $ad->id);
    expect($row)->toMatchArray(['shared' => true, 'owner_label' => 'Every organization', 'can' => ['update' => true, 'copy' => true, 'delete' => true]]);
    $this->get("/builder/{$ad->id}")->assertOk()->assertSee('dusk="ad-owner"', false)->assertSee('Every organization')->assertDontSee('dusk="ad-organization"', false);
});

test('an organization sees the platform\'s ad once it is published, as it was published, and with nobody\'s name from above', function () {
    $draft = makeForEveryOrganization($this, 'Spring sale', publish: false);
    $ad = makeForEveryOrganization($this, 'Winter sale');

    // The platform changes its ad after publishing it, and has not published that yet.
    $this->putJson("/builder/{$ad->id}", ['name' => 'Winter sale 2', 'document' => everyOrganizationDocument('Half done')])->assertOk()
        ->assertJsonPath('message', 'Changes saved — organizations keep the published version until you publish them');

    $seen = adsSeenIn($this, $this->designer, $this->organization);

    expect($seen->keys()->all())->toBe([$ad->id])
        ->and($seen[$ad->id])->toMatchArray([
            'name' => 'Winter sale',
            'shared' => true,
            'owner_label' => 'From the platform',
            'updated_by_name' => null,
            'can' => ['update' => false, 'copy' => true, 'delete' => false],
        ])
        ->and($seen[$ad->id]['thumbnail_url'])->toContain("builder/platform/ads/{$ad->id}/published.jpg");

    foreach (['document', 'published_document', 'published_name', 'created_by', 'updated_by'] as $key) {
        expect($seen[$ad->id])->not->toHaveKey($key);
    }

    // The draft's words, and the draft ad itself, are nowhere in what the organization is sent; the page opens.
    expect(json_encode($this->getJson('/builder/data')->json()))->not->toContain('Winter sale 2')->not->toContain('Spring sale');
    $this->get('/builder')->assertOk();

    // Searched by the name its card shows, it is found.
    expect(collect($this->getJson('/builder/data?search=Winter')->json('ads'))->pluck('id')->all())->toBe([$ad->id]);

    // Every organization sees it — and the draft, still, none.
    expect(adsSeenIn($this, createOrganizationUser($this->other, ['ad-view'], 'Beta viewer'), $this->other)->keys()->all())->toBe([$ad->id])
        ->and($draft->fresh()->isPublished())->toBeFalse();
});

test('an organization copies the platform\'s ad into its own Ads: the published version, under its name, its poster counted to the organization', function () {
    $ad = makeForEveryOrganization($this, 'Winter sale');
    $this->putJson("/builder/{$ad->id}", ['name' => 'Winter sale 2', 'document' => everyOrganizationDocument('Half done')])->assertOk();

    $this->actingAs($this->designer)->withSession(['current_organization_id' => $this->organization->id]);
    $before = app(OrganizationStorage::class)->summary($this->organization->id)['used'];

    $answer = $this->postJson("/builder/{$ad->id}/duplicate")->assertOk()->assertJsonPath('message', 'Copied to your ads');
    $copy = BuilderAd::findOrFail($answer->json('ad.id'));

    expect($copy->organization_id)->toBe($this->organization->id)
        ->and($copy->name)->toBe('Winter sale')
        ->and($copy->document['elements'][0]['text'])->toBe('Winter sale')
        ->and($copy->isPublished())->toBeFalse()
        ->and($copy->thumbnail_path)->toBe("builder/{$this->organization->id}/ads/{$copy->id}/poster.jpg")
        ->and(Storage::disk('public')->get($copy->thumbnail_path))->toBe(Storage::disk('public')->get($ad->media->thumbnail_path))
        ->and(app(OrganizationStorage::class)->summary($this->organization->id)['used'])->toBeGreaterThan($before);

    expect(ActivityLog::where('action', 'ad.copied')->sole())
        ->organization_id->toBe($this->organization->id)
        ->description->toBe('Copied ad Winter sale from the platform');

    // A second copy is named apart; the log says under which name.
    expect($this->postJson("/builder/{$ad->id}/duplicate")->assertOk()->json('ad.name'))->toBe('Winter sale (copy)')
        ->and(ActivityLog::where('action', 'ad.copied')->latest('id')->first()->description)
        ->toBe('Copied ad Winter sale from the platform as Winter sale (copy)');

    // The organization's own now: changed with Update Ads, published into the organization's library — and the platform's is untouched.
    $this->putJson("/builder/{$copy->id}", ['name' => 'Our winter sale', 'document' => everyOrganizationDocument('Ten percent off')])->assertOk();
    $this->postJson("/builder/{$copy->id}/publish")->assertOk();

    expect($copy->fresh()->media->organization_id)->toBe($this->organization->id)
        ->and($ad->fresh()->name)->toBe('Winter sale 2')
        ->and($ad->fresh()->published_name)->toBe('Winter sale');
});

test('an organization\'s people see, use and copy the platform\'s ad, and nothing more — whatever their role holds', function () {
    $ad = makeForEveryOrganization($this);
    $draft = makeForEveryOrganization($this, 'Spring sale', publish: false);

    // Every permission an organization's role may carry for its ads (owner, 2026-10-01: "srif delete nahi kar sakta ha").
    $this->actingAs($this->designer)->withSession(['current_organization_id' => $this->organization->id]);

    $this->get("/builder/{$ad->id}")->assertForbidden();
    $this->putJson("/builder/{$ad->id}", ['name' => 'Mine', 'document' => everyOrganizationDocument('Mine')])
        ->assertForbidden()->assertJsonPath('message', 'An ad for every organization is the platform\'s: copy it to change it.');
    $this->postJson("/builder/{$ad->id}/publish")->assertForbidden();
    $this->postJson("/builder/{$ad->id}/unpublish")->assertForbidden();
    $this->postJson("/builder/{$ad->id}/discard")->assertForbidden();
    $this->deleteJson("/builder/{$ad->id}", ['password' => 'password'])
        ->assertForbidden()->assertJsonPath('message', 'An ad for every organization is the platform\'s: only the platform deletes it.');

    // The draft is not there at all.
    $this->get("/builder/{$draft->id}")->assertNotFound();
    $this->get("/builder/{$draft->id}/preview")->assertNotFound();
    $this->putJson("/builder/{$draft->id}", ['name' => 'Mine', 'document' => everyOrganizationDocument('Mine')])->assertNotFound();
    $this->postJson("/builder/{$draft->id}/duplicate")->assertNotFound();
    $this->deleteJson("/builder/{$draft->id}", ['password' => 'password'])->assertNotFound();

    $ad->refresh();
    expect($ad->name)->toBe('Winter sale')
        ->and($ad->isPublished())->toBeTrue()
        ->and(BuilderAd::where('organization_id', $this->organization->id)->count())->toBe(0);
});

test('above the organizations the platform\'s ads are changed with Update Ads and deleted with Delete Ads, and copies stay', function () {
    $ad = makeForEveryOrganization($this);
    $draft = makeForEveryOrganization($this, 'Spring sale', publish: false);

    // Beta Deli copied it before.
    $this->actingAs(createOrganizationUser($this->other, ['ad-view', 'ad-store'], 'Beta designer'))->withSession(['current_organization_id' => $this->other->id]);
    $copyId = $this->postJson("/builder/{$ad->id}/duplicate")->assertOk()->json('ad.id');

    $platform = createPlatformUser(['ad-view', 'ad-store', 'ad-update', 'ad-destroy'], 'Platform designer');
    $this->actingAs($platform);
    $this->flushSession();

    $seen = collect($this->getJson('/builder/data')->assertOk()->json('ads'))->keyBy('id');
    expect($seen[$ad->id])->toMatchArray(['owner_label' => 'Every organization', 'can' => ['update' => true, 'copy' => true, 'delete' => true]])
        ->and($seen->has($draft->id))->toBeTrue();

    $this->get("/builder/{$ad->id}")->assertOk()->assertSee('Every organization');
    $this->putJson("/builder/{$ad->id}", ['name' => 'Winter sale', 'document' => everyOrganizationDocument('Twenty percent off')])->assertOk()
        ->assertJsonPath('message', 'Changes saved — organizations keep the published version until you publish them');
    $this->postJson("/builder/{$ad->id}/publish")->assertOk()->assertJsonPath('message', 'Published — every organization sees it now and can copy it');

    expect($ad->fresh()->published_document['elements'][0]['text'])->toBe('Twenty percent off')
        ->and(ActivityLog::where('action', 'ad.updated')->sole()->organization_id)->toBeNull();

    // Taken off, it leaves every organization's Ads until it is published again.
    $this->postJson("/builder/{$ad->id}/unpublish")->assertOk()->assertJsonPath('message', 'Unpublished — it is a draft again. Organizations no longer see it');
    expect(adsSeenIn($this, $this->designer, $this->organization)->all())->toBe([]);

    // A channel showing it keeps it; out of the channel it goes — and Beta Deli's copy stays theirs.
    $this->actingAs($platform);
    $this->flushSession();
    $this->postJson("/builder/{$ad->id}/publish")->assertOk();
    $channelAd = ChannelAd::factory()->create(['channel_id' => Channel::factory()->create()->id, 'media_id' => $ad->media_id]);

    $this->deleteJson("/builder/{$ad->id}", ['password' => 'password'])->assertStatus(422)->assertJsonValidationErrors('name');
    $channelAd->delete();
    $this->deleteJson("/builder/{$ad->id}", ['password' => 'password'])->assertOk();

    expect(BuilderAd::find($ad->id))->toBeNull()
        ->and(Media::find($ad->media_id))->toBeNull()
        ->and(BuilderAd::find($copyId)?->organization_id)->toBe($this->other->id)
        ->and(ActivityLog::where('action', 'ad.deleted')->sole()->description)->toBe('Deleted ad Winter sale, shared with every organization');
    Storage::disk('public')->assertMissing("builder/platform/ads/{$ad->id}/index.html");
});

test('above the organizations a copy of the platform\'s ad stays the platform\'s; without Update Ads a platform role only looks', function () {
    $ad = makeForEveryOrganization($this);

    $copyId = $this->postJson("/builder/{$ad->id}/duplicate")->assertOk()->assertJsonPath('message', 'Ad duplicated')->json('ad.id');
    expect(BuilderAd::find($copyId))->organization_id->toBeNull()->name->toBe('Winter sale (copy)');

    $this->actingAs(createPlatformUser(['ad-view'], 'Platform viewer'));
    $this->flushSession();

    expect(collect($this->getJson('/builder/data')->assertOk()->json('ads'))->firstWhere('id', $ad->id))
        ->toMatchArray(['owner_label' => 'Every organization', 'can' => ['update' => false, 'copy' => false, 'delete' => false]]);

    $this->postJson("/builder/{$ad->id}/duplicate")->assertForbidden();
    $this->putJson("/builder/{$ad->id}", ['name' => 'X', 'document' => everyOrganizationDocument('X')])->assertForbidden();
    $this->deleteJson("/builder/{$ad->id}", ['password' => 'password'])->assertForbidden();

    expect(BuilderAd::whereNull('organization_id')->count())->toBe(2);
});

test('an ad for every organization uses the shared files alone: its editor offers no organization\'s own, and its page shows none', function () {
    $shared = BuilderAsset::factory()->create(['organization_id' => null, 'title' => 'Brand logo', 'path' => 'builder/platform/assets/brand-logo.jpg']);
    $own = BuilderAsset::factory()->create(['organization_id' => $this->organization->id, 'title' => 'Alpha logo', 'path' => "builder/{$this->organization->id}/assets/alpha-logo.jpg"]);

    $document = everyOrganizationDocument('Winter sale', $shared->id);
    $document['elements'][] = [...$document['elements'][1], 'id' => 'el_3', 'assetId' => $own->id];
    $ad = BuilderAd::factory()->create(['organization_id' => null, 'name' => 'Every organization', 'document' => $document]);

    $this->actingAs(createSuperAdmin());

    $this->get("/builder/{$ad->id}")->assertOk()->assertSee('Brand logo')->assertDontSee('Alpha logo');
    $this->get("/builder/{$ad->id}/preview")->assertOk()
        ->assertSee('builder/platform/assets/brand-logo.jpg', false)
        ->assertDontSee('alpha-logo.jpg', false);
});

test('the platform\'s channel takes a published ad for every organization from the platform\'s library', function () {
    $ad = makeForEveryOrganization($this);
    $channel = Channel::factory()->create();

    $ids = collect($this->getJson("/channels/{$channel->id}/library?type=html")->assertOk()->json('media'))->pluck('id')->all();

    expect($ids)->toBe([$ad->media_id]);
});

test('an organization is previewed the platform\'s ad as it was published; the platform its draft', function () {
    $ad = makeForEveryOrganization($this);
    $this->putJson("/builder/{$ad->id}", ['name' => 'Winter sale', 'document' => everyOrganizationDocument('Half done')])->assertOk();

    $this->get("/builder/{$ad->id}/preview")->assertOk()->assertSee('Half done');

    $this->actingAs($this->designer)->withSession(['current_organization_id' => $this->organization->id]);
    $this->get("/builder/{$ad->id}/preview")->assertOk()->assertSee('Winter sale')->assertDontSee('Half done');
});

test('a shared file an ad for every organization uses stays, and an organization is told so without its name', function () {
    $file = BuilderAsset::factory()->create(['organization_id' => null, 'title' => 'Brand logo']);
    BuilderAd::factory()->create(['organization_id' => null, 'name' => 'Secret launch', 'document' => everyOrganizationDocument('Soon', $file->id)]);

    // An organization's shelf counts the platform's ad that uses it, never naming it — and offers no delete.
    $this->actingAs($this->designer)->withSession(['current_organization_id' => $this->organization->id]);

    $row = collect($this->getJson('/builder/assets/data')->json('assets'))->firstWhere('id', $file->id);
    expect($row)->toMatchArray(['used_by' => [], 'used_elsewhere' => 0, 'used_by_platform' => 1, 'can_delete' => false])
        ->and(json_encode($row))->not->toContain('Secret launch');

    // Above the organizations the ad is named, as an ad for every organization, and the file stays while it uses it.
    $this->actingAs(createSuperAdmin());
    $this->flushSession();

    expect(collect($this->getJson('/builder/assets/data')->json('assets'))->firstWhere('id', $file->id)['used_by'])->toBe(['Secret launch (every organization)']);
    $this->deleteJson("/builder/assets/{$file->id}")->assertStatus(422)
        ->assertJsonValidationErrors(['title' => 'Still used by Secret launch (every organization). Take it out of those ads first, and publish the ones whose screens still show it.']);
});

test('a deleted organization leaves the platform\'s ads', function () {
    $ad = makeForEveryOrganization($this);

    $this->organization->delete();

    expect(BuilderAd::find($ad->id))->not->toBeNull();
    Storage::disk('public')->assertExists($ad->media->path);
});

test('no permission lets an organization change or delete what the platform shares: the three shared permissions are gone', function () {
    $removed = ['ad-shared-update' => 'Update Shared Ads', 'ad-shared-destroy' => 'Delete Shared Ads', 'ad-shared-asset-destroy' => 'Delete Shared Assets'];

    foreach (array_keys($removed) as $name) {
        expect(Permission::LABELS)->not->toHaveKey($name)
            ->and(Permission::PLATFORM)->not->toContain($name)
            ->and(Permission::ORGANIZATION_SCOPED)->not->toContain($name)
            ->and(DB::table('permissions')->where('name', $name)->exists())->toBeFalse();
    }
});
