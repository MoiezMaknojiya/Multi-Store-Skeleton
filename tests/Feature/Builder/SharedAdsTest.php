<?php

use App\Models\ActivityLog;
use App\Models\BuilderAd;
use App\Models\BuilderAsset;
use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Media;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Screen;
use App\Models\User;
use App\Services\OrganizationStorage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Ads for every organization — the Premium Templates
|--------------------------------------------------------------------------
|
| Owner, 2026-10-01: "mein all shop k liya ads kese banao?" Above the organizations an ad with no organization chosen — All
| organizations — is the platform's: it uses the platform's files alone, publishes into the platform's own library, and only
| above the organizations is it changed (Update Ads) or deleted (Delete Ads). Owner, 2026-10-07: published, it is a Premium
| Template, which an organization finds under Create Ad and makes its own copy of (PremiumTemplatesTest) — never listed, opened
| or copied from an organization's Ads page.
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
        $test->postJson("/builder/{$id}/publish")->assertOk()->assertJsonPath('message', 'Published — every organization finds it under Premium Template now');
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
    expect($row)->toMatchArray(['shared' => true, 'owner_label' => 'Premium Template', 'from_template' => false, 'can' => ['update' => true, 'copy' => true, 'delete' => true]]);
    $this->get("/builder/{$ad->id}")->assertOk()->assertSee('dusk="ad-owner">Premium Template<', false)->assertDontSee('dusk="ad-organization"', false);
});

test('an organization\'s Ads page lists its own ads alone: the platform\'s, published or not, are nothing there to open, copy, change or delete', function () {
    $ad = makeForEveryOrganization($this, 'Winter sale');
    $draft = makeForEveryOrganization($this, 'Spring sale', publish: false);
    $own = BuilderAd::factory()->withText('Our sale')->create(['organization_id' => $this->organization->id, 'name' => 'Our sale']);

    expect(adsSeenIn($this, $this->designer, $this->organization)->keys()->all())->toBe([$own->id])
        ->and(json_encode($this->getJson('/builder/data?search=sale')->json()))->not->toContain('Winter sale')->not->toContain('Spring sale')
        ->and(adsSeenIn($this, createOrganizationUser($this->other, ['ad-view'], 'Beta viewer'), $this->other)->all())->toBe([]);

    // Whatever the role holds (owner, 2026-10-01: "srif delete nahi kar sakta ha"), it is not there to act on at all.
    $this->actingAs($this->designer)->withSession(['current_organization_id' => $this->organization->id]);

    foreach ([$ad, $draft] as $platformAd) {
        $this->get("/builder/{$platformAd->id}")->assertNotFound();
        $this->putJson("/builder/{$platformAd->id}", ['name' => 'Mine', 'document' => everyOrganizationDocument('Mine')])->assertNotFound();
        $this->postJson("/builder/{$platformAd->id}/publish")->assertNotFound();
        $this->postJson("/builder/{$platformAd->id}/unpublish")->assertNotFound();
        $this->postJson("/builder/{$platformAd->id}/discard")->assertNotFound();
        $this->postJson("/builder/{$platformAd->id}/duplicate")->assertNotFound();
        $this->deleteJson("/builder/{$platformAd->id}", ['password' => 'password'])->assertNotFound();
    }

    // A draft is no template: not even its Preview.
    $this->get("/builder/{$draft->id}/preview")->assertNotFound();

    $ad->refresh();
    expect($ad->name)->toBe('Winter sale')
        ->and($ad->isPublished())->toBeTrue()
        ->and(BuilderAd::where('organization_id', $this->organization->id)->count())->toBe(1);
});

test('above the organizations the platform\'s ads are changed with Update Ads and deleted with Delete Ads, and the copies organizations made stay', function () {
    $ad = makeForEveryOrganization($this);
    $draft = makeForEveryOrganization($this, 'Spring sale', publish: false);

    // Beta Deli made its own of it before, with Use This Template.
    $this->actingAs(createOrganizationUser($this->other, ['ad-view', 'ad-store'], 'Beta designer'))->withSession(['current_organization_id' => $this->other->id]);
    $copyId = $this->postJson("/builder/templates/{$ad->id}")->assertOk()->json('ad.id');

    $platform = createPlatformUser(['ad-view', 'ad-store', 'ad-update', 'ad-destroy'], 'Platform designer');
    $this->actingAs($platform);
    $this->flushSession();

    // All: every card says whose it is (owner, 2026-10-08) — a published one a Premium Template, a draft the Platform's, and
    // Beta Deli's copy Beta Deli's, made from a template.
    $seen = collect($this->getJson('/builder/data')->assertOk()->json('ads'))->keyBy('id');
    expect($seen[$ad->id])->toMatchArray(['owner_label' => 'Premium Template', 'can' => ['update' => true, 'copy' => true, 'delete' => true]])
        ->and($seen[$draft->id]['owner_label'])->toBe('Platform')
        ->and($seen[$copyId])->toMatchArray(['owner_label' => 'Beta Deli', 'shared' => false, 'from_template' => true]);

    // Platform, where the page opens: the platform's own alone; an organization: its own alone.
    expect(collect($this->getJson('/builder/data?organization_id=platform')->assertOk()->json('ads'))->pluck('id')->sort()->values()->all())
        ->toBe(collect([$ad->id, $draft->id])->sort()->values()->all())
        ->and(collect($this->getJson('/builder/data?organization_id='.$this->other->id)->assertOk()->json('ads'))->pluck('id')->all())->toBe([$copyId]);

    $this->get("/builder/{$ad->id}")->assertOk()->assertSee('dusk="ad-owner">Premium Template<', false);
    $this->putJson("/builder/{$ad->id}", ['name' => 'Winter sale', 'document' => everyOrganizationDocument('Twenty percent off')])->assertOk()
        ->assertJsonPath('message', 'Changes saved — organizations keep the published version until you publish them');
    $this->postJson("/builder/{$ad->id}/publish")->assertOk()->assertJsonPath('message', 'Published — every organization finds it under Premium Template now');

    expect($ad->fresh()->published_document['elements'][0]['text'])->toBe('Twenty percent off')
        ->and(ActivityLog::where('action', 'ad.updated')->sole()->organization_id)->toBeNull();

    // Taken off, it is no Premium Template until it is published again.
    $this->postJson("/builder/{$ad->id}/unpublish")->assertOk()
        ->assertJsonPath('message', 'Unpublished — it is a draft again. It is no Premium Template until it is published again');
    $this->actingAs($this->designer)->withSession(['current_organization_id' => $this->organization->id]);
    expect($this->getJson('/builder/templates?orientation=landscape')->assertOk()->json('templates'))->toBe([]);

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
        ->toMatchArray(['owner_label' => 'Premium Template', 'can' => ['update' => false, 'copy' => false, 'delete' => false]]);

    $this->postJson("/builder/{$ad->id}/duplicate")->assertForbidden();
    $this->putJson("/builder/{$ad->id}", ['name' => 'X', 'document' => everyOrganizationDocument('X')])->assertForbidden();
    $this->deleteJson("/builder/{$ad->id}", ['password' => 'password'])->assertForbidden();

    expect(BuilderAd::whereNull('organization_id')->count())->toBe(2);
});

test('an ad for every organization uses the platform\'s files alone: its editor offers no organization\'s own, and its page shows none', function () {
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

test('no organization\'s playlist takes the platform\'s published ad: it reaches a screen as the organization\'s own copy', function () {
    $ad = makeForEveryOrganization($this);
    $screen = Screen::factory()->create(['organization_id' => $this->organization->id]);
    $manager = createOrganizationUser($this->organization, ['screen-view', 'screen-playlist'], 'Manager');
    $this->actingAs($manager)->withSession(['current_organization_id' => $this->organization->id]);

    // Not offered, and refused by name when posted by hand (docs/BILLING-SPEC.md §5).
    expect(collect($this->getJson("/screens/{$screen->id}/available-media")->assertOk()->json('media'))->pluck('id'))->not->toContain($ad->media_id);

    $version = $this->getJson("/screens/{$screen->id}/playlist")->json('version');
    $this->putJson("/screens/{$screen->id}/playlist", ['version' => $version, 'items' => [['media_id' => $ad->media_id, 'duration_seconds' => 10]]])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['items' => "Winter sale is the platform's, and the Content Library holds your own files alone. Take its line out."]);
});

test('an organization previews a Premium Template as it was published; the platform its draft', function () {
    $ad = makeForEveryOrganization($this);
    $this->putJson("/builder/{$ad->id}", ['name' => 'Winter sale', 'document' => everyOrganizationDocument('Half done')])->assertOk();

    $this->get("/builder/{$ad->id}/preview")->assertOk()->assertSee('Half done');

    $this->actingAs($this->designer)->withSession(['current_organization_id' => $this->organization->id]);
    $this->get("/builder/{$ad->id}/preview")->assertOk()->assertSee('Winter sale')->assertDontSee('Half done');

    // Somebody who may not make an ad has no template to look at.
    $this->actingAs(createOrganizationUser($this->organization, ['ad-view'], 'Viewer'))->withSession(['current_organization_id' => $this->organization->id]);
    $this->get("/builder/{$ad->id}/preview")->assertForbidden();
});

test('the platform\'s file a platform ad uses stays, and says which ad; an organization never reaches it', function () {
    $file = BuilderAsset::factory()->create(['organization_id' => null, 'title' => 'Brand logo']);
    BuilderAd::factory()->create(['organization_id' => null, 'name' => 'Secret launch', 'document' => everyOrganizationDocument('Soon', $file->id)]);

    // Not on an organization's shelf at all, so nothing of the platform's ad is told there either.
    $this->actingAs($this->designer)->withSession(['current_organization_id' => $this->organization->id]);

    expect(collect($this->getJson('/builder/assets/data')->json('assets'))->pluck('id')->all())->not->toContain($file->id)
        ->and(json_encode($this->getJson('/builder/assets/data')->json()))->not->toContain('Secret launch');
    $this->deleteJson("/builder/assets/{$file->id}")->assertNotFound();

    // Above the organizations the ad is named, and the file stays while it uses it.
    $this->actingAs(createSuperAdmin());
    $this->flushSession();

    expect(collect($this->getJson('/builder/assets/data')->json('assets'))->firstWhere('id', $file->id)['used_by'])->toBe(['Secret launch']);
    $this->deleteJson("/builder/assets/{$file->id}")->assertStatus(422)
        ->assertJsonValidationErrors(['title' => 'Still used by Secret launch. Take it out of those ads first, and publish the ones whose screens still show it.']);
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
