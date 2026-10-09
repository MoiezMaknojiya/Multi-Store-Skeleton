<?php

use App\Models\BuilderAd;
use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Media;
use App\Models\Organization;
use App\Models\Screen;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\VideoFiles;

test('guests cannot access any media endpoint', function () {
    // Every route under /media, read from the route table — so one added later is asked too.
    $routes = routesUnder('media');

    expect($routes)->not->toBeEmpty();

    foreach ($routes as [$method, $uri]) {
        expect($this->json($method, $uri)->status())->toBe(401, "{$method} {$uri}");
    }
});

test('an organization user only sees the media of the organization they are working in', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();
    $actor = createOrganizationUser($organizationA, ['media-view']);

    $mine = Media::factory()->create(['organization_id' => $organizationA->id, 'title' => 'Alpha Menu']);
    $theirs = Media::factory()->create(['organization_id' => $organizationB->id, 'title' => 'Beta Menu']);

    $ids = collect($this->actingAs($actor)->withSession(['current_organization_id' => $organizationA->id])
        ->getJson('/media/data')->assertOk()->json('media'))->pluck('id');

    expect($ids)->toContain($mine->id);
    expect($ids)->not->toContain($theirs->id);
});

test('media belongs to the organization, not the uploader — a colleague in the same organization sees it', function () {
    $organization = Organization::factory()->create();
    $uploader = createOrganizationUser($organization, ['media-view', 'media-store'], 'Uploader Role');
    $colleague = createOrganizationUser($organization, ['media-view'], 'Colleague Role');

    $file = Media::factory()->create(['organization_id' => $organization->id, 'created_by' => $uploader->id]);

    $ids = collect($this->actingAs($colleague)->withSession(['current_organization_id' => $organization->id])
        ->getJson('/media/data')->assertOk()->json('media'))->pluck('id');

    expect($ids)->toContain($file->id);
});

test('another organization\'s media is unreachable, not just hidden', function () {
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();
    $actor = createOrganizationUser($organizationA, ['media-view', 'media-update', 'media-destroy']);
    $theirs = Media::factory()->create(['organization_id' => $organizationB->id, 'title' => 'Beta Menu']);

    // 404, never 403: from this organization that file does not exist.
    $this->actingAs($actor)->withSession(['current_organization_id' => $organizationA->id])
        ->putJson("/media/{$theirs->id}", ['title' => 'Hacked'])
        ->assertNotFound();

    $this->actingAs($actor)->withSession(['current_organization_id' => $organizationA->id])
        ->deleteJson("/media/{$theirs->id}")
        ->assertNotFound();

    $this->assertDatabaseHas('media', ['id' => $theirs->id, 'title' => 'Beta Menu']);
});

test('with no organization selected an organization user cannot reach the library at all', function () {
    $organization = Organization::factory()->create();
    // One of two organizations, none chosen: a lone organization is chosen for its person (ChooseTheOnlyOrganization).
    $actor = inASecondOrganization(createOrganizationUser($organization, ['media-view']));
    Media::factory()->create(['organization_id' => $organization->id]);

    // Permissions resolve against the CURRENT organization's role, so with no organization in
    // context the gate denies before the scope is ever consulted.
    $this->actingAs($actor)->getJson('/media/data')->assertForbidden();

    // The scope agrees: no organization, nothing visible.
    expect(Media::visibleTo($actor)->count())->toBe(0);
});

test('a super admin sees the media of every organization', function () {
    $admin = createSuperAdmin(['media-view']);
    $organizationA = Organization::factory()->create();
    $organizationB = Organization::factory()->create();
    Media::factory()->create(['organization_id' => $organizationA->id]);
    Media::factory()->create(['organization_id' => $organizationB->id]);

    $ids = collect($this->actingAs($admin)->getJson('/media/data')->assertOk()->json('media'))->pluck('id');

    expect($ids)->toHaveCount(2);
});

test('a user with media-store can upload an image, stamped with their organization', function () {
    Storage::fake('public');
    $organization = Organization::factory()->create();
    $actor = createOrganizationUser($organization, ['media-store']);

    $response = $this->actingAs($actor)
        ->withSession(['current_organization_id' => $organization->id])
        ->post('/media', ['file' => UploadedFile::fake()->image('menu.jpg', 1920, 1080)]);

    $response->assertOk();
    $this->assertDatabaseHas('media', [
        'organization_id' => $organization->id,
        'created_by' => $actor->id,
        'title' => 'menu',
        'type' => Media::TYPE_IMAGE,
        'orientation' => 'landscape',
    ]);

    $media = Media::firstOrFail();
    Storage::disk('public')->assertExists($media->path);
    expect($media->thumbnail_path)->not->toBeNull();
    Storage::disk('public')->assertExists($media->thumbnail_path);
});

test('a portrait upload is recorded as portrait', function () {
    Storage::fake('public');
    $organization = Organization::factory()->create();
    $actor = createOrganizationUser($organization, ['media-store']);

    $this->actingAs($actor)
        ->withSession(['current_organization_id' => $organization->id])
        ->post('/media', ['file' => UploadedFile::fake()->image('poster.jpg', 1080, 1920)])
        ->assertOk();

    expect(Media::firstOrFail()->orientation)->toBe('portrait');
});

test('above the organizations, an upload with no organization chosen joins the platform\'s own library', function () {
    // docs/CHANNEL-CONTENT-SPEC.md: the platform keeps a library of its own (organization_id NULL), which is
    // where its channels' files live.
    Storage::fake('public');
    $admin = createSuperAdmin(['media-store']);

    $this->actingAs($admin)
        ->postJson('/media', ['file' => UploadedFile::fake()->image('menu.jpg')])
        ->assertOk();

    $media = Media::firstOrFail();
    expect($media->organization_id)->toBeNull();
    expect($media->path)->toStartWith('media/platform/');
    Storage::disk('public')->assertExists($media->path);
    $this->assertDatabaseHas('activity_logs', [
        'action' => 'media.uploaded', 'organization_id' => null, 'description' => "Uploaded image menu to the platform's library",
    ]);
});

test('above the organizations, an upload may go straight into an organization\'s library', function () {
    Storage::fake('public');
    $organization = Organization::factory()->create();
    $admin = createSuperAdmin(['media-store']);

    $this->actingAs($admin)
        ->postJson('/media', ['file' => UploadedFile::fake()->image('menu.jpg'), 'organization_id' => $organization->id])
        ->assertOk();

    $media = Media::firstOrFail();
    expect($media->organization_id)->toBe($organization->id);
    expect($media->path)->toStartWith("media/{$organization->id}/");
    $this->assertDatabaseHas('activity_logs', ['action' => 'media.uploaded', 'organization_id' => $organization->id]);
});

test('an upload into an organization that no longer exists is refused, and nothing is kept', function (mixed $organizationId, int $status) {
    Storage::fake('public');
    $admin = createSuperAdmin(['media-store']);

    $this->actingAs($admin)
        ->postJson('/media', ['file' => UploadedFile::fake()->image('menu.jpg'), 'organization_id' => $organizationId])
        ->assertStatus($status);

    expect(Media::count())->toBe(0);
    expect(Storage::disk('public')->allFiles())->toBe([]);
})->with([
    'a deleted organization' => [999999, 422],
    'no id at all' => [0, 422],
    'a word' => ['alpha', 422],
    'a list' => [[1], 422],
]);

test('an organization member with no organization selected cannot upload at all', function () {
    // Their permissions are read against the organization they work in; with none chosen they hold none — and an
    // organization's person has no library of their own to fall back on.
    Storage::fake('public');
    $organization = Organization::factory()->create();
    $member = inASecondOrganization(createOrganizationUser($organization, ['media-store']));

    $this->actingAs($member)
        ->postJson('/media', ['file' => UploadedFile::fake()->image('menu.jpg')])
        ->assertForbidden();

    expect(Media::count())->toBe(0);
});

test('a file type the player cannot render is rejected', function () {
    Storage::fake('public');
    $organization = Organization::factory()->create();
    $actor = createOrganizationUser($organization, ['media-store']);

    $this->actingAs($actor)
        ->withSession(['current_organization_id' => $organization->id])
        ->postJson('/media', ['file' => UploadedFile::fake()->create('prices.pdf', 100, 'application/pdf')])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file']);

    expect(Media::count())->toBe(0);
});

test('audio is not signage: an mp3 is rejected', function () {
    Storage::fake('public');
    $organization = Organization::factory()->create();
    $actor = createOrganizationUser($organization, ['media-store']);

    // A screen has no sound, so audio has nothing to show. Rejected at the door
    // rather than sitting silently in a library forever.
    $this->actingAs($actor)
        ->withSession(['current_organization_id' => $organization->id])
        ->postJson('/media', ['file' => UploadedFile::fake()->create('jingle.mp3', 500, 'audio/mpeg')])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file']);

    expect(Media::count())->toBe(0);
});

test('the formats a player can actually render are accepted', function () {
    Storage::fake('public');
    $organization = Organization::factory()->create();
    $actor = createOrganizationUser($organization, ['media-store']);

    foreach (['menu.jpg', 'menu.jpeg', 'menu.png', 'menu.gif', 'menu.webp'] as $name) {
        $this->actingAs($actor)
            ->withSession(['current_organization_id' => $organization->id])
            ->post('/media', ['file' => UploadedFile::fake()->image($name)])
            ->assertOk();
        $this->flushSession();
    }

    // Both video formats too, so the list in ALLOWED_MIMES is covered end to end
    // and not just its image half.
    foreach (['clip.mp4' => VideoFiles::mp4(20), 'clip.webm' => VideoFiles::webm(20)] as $name => $bytes) {
        $this->actingAs($actor)
            ->withSession(['current_organization_id' => $organization->id])
            ->post('/media', ['file' => VideoFiles::upload($bytes, $name)])
            ->assertOk();
        $this->flushSession();
    }

    expect(Media::count())->toBe(7);
    expect(Media::where('type', Media::TYPE_IMAGE)->count())->toBe(5);
    expect(Media::where('type', Media::TYPE_VIDEO)->count())->toBe(2);
});

test('a user without media-store cannot upload', function () {
    Storage::fake('public');
    $organization = Organization::factory()->create();
    $actor = createOrganizationUser($organization, ['media-view']);

    $this->actingAs($actor)
        ->withSession(['current_organization_id' => $organization->id])
        ->postJson('/media', ['file' => UploadedFile::fake()->image('menu.jpg')])
        ->assertForbidden();
});

test('a user with media-update renames a file, and nothing else of what is sent is kept', function () {
    $organization = Organization::factory()->create();
    $actor = createOrganizationUser($organization, ['media-update']);
    $media = Media::factory()->create(['organization_id' => $organization->id, 'title' => 'IMG_2041']);

    // A file keeps its name alone (owner, 2026-10-01): when it plays is said on its playlist line.
    $this->actingAs($actor)->withSession(['current_organization_id' => $organization->id])
        ->putJson("/media/{$media->id}", [
            'title' => 'Breakfast Menu',
            'description' => 'Shown until 11am',
            'starts_at' => '2026-10-01T06:00:00Z',
            'expires_at' => '2026-10-31T11:00:00Z',
            'organization_id' => 999,
        ])->assertOk()->assertJsonPath('message', 'File renamed.');

    expect($media->fresh()->title)->toBe('Breakfast Menu')
        ->and($media->fresh()->organization_id)->toBe($organization->id)
        ->and(array_keys($media->fresh()->getAttributes()))->not->toContain('description', 'starts_at', 'expires_at');

    $this->assertDatabaseHas('activity_logs', [
        'action' => 'media.renamed', 'organization_id' => $organization->id, 'description' => 'Renamed IMG_2041 to Breakfast Menu',
    ]);
});

test('a name is required, and no longer than 255 characters', function () {
    $organization = Organization::factory()->create();
    $actor = createOrganizationUser($organization, ['media-update']);
    $media = Media::factory()->create(['organization_id' => $organization->id, 'title' => 'Menu']);

    $rename = fn (mixed $title) => $this->actingAs($actor)->withSession(['current_organization_id' => $organization->id])
        ->putJson("/media/{$media->id}", ['title' => $title]);

    $rename('')->assertStatus(422)->assertJsonPath('errors.title.0', 'Title is required.');
    $rename(str_repeat('x', 256))->assertStatus(422)->assertJsonPath('errors.title.0', 'Title may not be longer than 255 characters.');
    $rename(['an', 'array'])->assertStatus(422)->assertJsonValidationErrors('title');
    expect($media->fresh()->title)->toBe('Menu');
});

test('a user with media-destroy deletes the row and the files on disk', function () {
    Storage::fake('public');
    $organization = Organization::factory()->create();
    $actor = createOrganizationUser($organization, ['media-store', 'media-destroy']);

    $this->actingAs($actor)->withSession(['current_organization_id' => $organization->id])
        ->post('/media', ['file' => UploadedFile::fake()->image('menu.jpg')])->assertOk();

    $media = Media::firstOrFail();

    $this->actingAs($actor)->withSession(['current_organization_id' => $organization->id])
        ->deleteJson("/media/{$media->id}")->assertOk();

    $this->assertDatabaseMissing('media', ['id' => $media->id]);
    Storage::disk('public')->assertMissing($media->path);
    Storage::disk('public')->assertMissing($media->thumbnail_path);
});

test('a file a channel shows is not deleted until it is taken out of the channel, which the refusal names', function () {
    // docs/CHANNEL-CONTENT-SPEC.md, owner 2026-09-19: "pehle channel se hatao". A playlist line is not a
    // reason to refuse — deleting a file still takes it off the playlists, as before.
    Storage::fake('public');
    $organization = Organization::factory()->create();
    $actor = createOrganizationUser($organization, ['media-view', 'media-destroy']);
    $media = Media::factory()->create(['organization_id' => $organization->id]);
    Storage::disk('public')->put($media->path, 'image');
    $channel = Channel::factory()->create(['organization_id' => $organization->id, 'name' => 'Weekly Deals']);
    $ad = ChannelAd::factory()->create(['channel_id' => $channel->id, 'media_id' => $media->id]);

    $this->actingAs($actor)->withSession(['current_organization_id' => $organization->id])
        ->deleteJson("/media/{$media->id}")
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file' => 'Still used by the channel Weekly Deals. Take it out of that channel first.']);

    expect(Media::find($media->id))->not->toBeNull()
        ->and(ChannelAd::find($ad->id))->not->toBeNull();
    Storage::disk('public')->assertExists($media->path);

    // Out of the channel, it deletes like any file.
    $ad->delete();
    $this->deleteJson("/media/{$media->id}")->assertOk();

    expect(Media::find($media->id))->toBeNull();
    Storage::disk('public')->assertMissing($media->path);
});

test('the listing carries the refusal with each file a channel shows, so the page says it before any confirmation', function () {
    $organization = Organization::factory()->create();
    $actor = createOrganizationUser($organization, ['media-view']);
    [$held, $free] = Media::factory()->count(2)->create(['organization_id' => $organization->id]);
    $channel = Channel::factory()->create(['organization_id' => $organization->id, 'name' => 'Weekly Deals']);
    ChannelAd::factory()->count(2)->create(['channel_id' => $channel->id, 'media_id' => $held->id]);

    $rows = collect($this->actingAs($actor)->withSession(['current_organization_id' => $organization->id])
        ->getJson('/media/data')->assertOk()->json('media'))->keyBy('id');

    // The same words the delete itself would answer with — one channel, however many of its ads show the file.
    expect($rows[$held->id]['in_channels_message'])->toBe('Still used by the channel Weekly Deals. Take it out of that channel first.')
        ->and($rows[$held->id]['in_channels_message'])->toBe($held->stillInAChannelMessage())
        ->and($rows[$free->id]['in_channels_message'])->toBeNull();
});

test('the library chooser is offered above the organizations, and nothing of the sort inside an organization', function () {
    $alpha = Organization::factory()->create(['name' => 'Alpha Mart']);

    $this->actingAs(createSuperAdmin(['media-view', 'media-store']))->get('/media')->assertOk()
        ->assertSee('dusk="media-filter-library"', false)
        ->assertSee('<option value="platform">Platform library</option>', false)
        ->assertSee('<option value="'.$alpha->id.'">Alpha Mart</option>', false);

    $this->actingAs(createOrganizationUser($alpha, ['media-view', 'media-store']))->withSession(['current_organization_id' => $alpha->id])
        ->get('/media')->assertOk()
        ->assertDontSee('dusk="media-filter-library"', false)
        ->assertDontSee('Platform library');
});

test('the refusal names at most three channels, in order, and counts the rest', function () {
    $media = Media::factory()->platformOwned()->create();
    foreach (['Echo', 'Alpha', 'Delta', 'Bravo', 'Charlie'] as $name) {
        ChannelAd::factory()->create(['channel_id' => Channel::factory()->create(['name' => $name])->id, 'media_id' => $media->id]);
    }
    // The same channel twice is still one channel.
    ChannelAd::factory()->create(['channel_id' => Channel::firstWhere('name', 'Alpha')->id, 'media_id' => $media->id]);

    expect($media->stillInAChannelMessage())
        ->toBe('Still used by the channels Alpha, Bravo, Charlie and 2 more. Take it out of those channels first.')
        ->and(Media::factory()->create()->stillInAChannelMessage())->toBeNull();
});

test('above the organizations the page reads one library at a time — the platform\'s, or an organization\'s — or all of them', function () {
    $alpha = Organization::factory()->create(['name' => 'Alpha Mart']);
    $beta = Organization::factory()->create(['name' => 'Beta Deli']);
    Media::factory()->platformOwned()->create(['title' => 'Platform promo']);
    Media::factory()->create(['organization_id' => $alpha->id, 'title' => 'Alpha poster']);
    Media::factory()->create(['organization_id' => $beta->id, 'title' => 'Beta poster']);
    $admin = createSuperAdmin(['media-view']);

    $titles = fn (string $query = '') => collect($this->actingAs($admin)->getJson("/media/data{$query}")->assertOk()->json('media'))
        ->pluck('title')->sort()->values()->all();

    expect($titles('?library=platform'))->toBe(['Platform promo'])
        ->and($titles("?library={$alpha->id}"))->toBe(['Alpha poster'])
        ->and($titles())->toBe(['Alpha poster', 'Beta poster', 'Platform promo']);

    // Each row says whose library it is in.
    $rows = collect($this->getJson('/media/data')->json('media'))->keyBy('title');
    expect($rows['Platform promo']['organization'])->toBeNull()
        ->and($rows['Alpha poster']['organization']['name'])->toBe('Alpha Mart');
});

test('a user without media-destroy cannot delete a file', function () {
    $organization = Organization::factory()->create();
    $actor = createOrganizationUser($organization, ['media-view']);
    $media = Media::factory()->create(['organization_id' => $organization->id]);

    $this->actingAs($actor)->withSession(['current_organization_id' => $organization->id])
        ->deleteJson("/media/{$media->id}")->assertForbidden();

    $this->assertDatabaseHas('media', ['id' => $media->id]);
});

test('the listing can be filtered by type and orientation', function () {
    $organization = Organization::factory()->create();
    $actor = createOrganizationUser($organization, ['media-view']);

    $image = Media::factory()->create(['organization_id' => $organization->id]);
    $video = Media::factory()->video()->create(['organization_id' => $organization->id]);
    $portrait = Media::factory()->create(['organization_id' => $organization->id, 'orientation' => 'portrait']);

    $videoIds = collect($this->actingAs($actor)->withSession(['current_organization_id' => $organization->id])
        ->getJson('/media/data?type=video')->assertOk()->json('media'))->pluck('id');
    expect($videoIds->all())->toBe([$video->id]);

    $this->flushSession();
    $portraitIds = collect($this->actingAs($actor)->withSession(['current_organization_id' => $organization->id])
        ->getJson('/media/data?orientation=portrait')->assertOk()->json('media'))->pluck('id');
    expect($portraitIds->all())->toBe([$portrait->id]);
    expect($portraitIds)->not->toContain($image->id);
});

test('deleting the uploader keeps the organization\'s media — the file belongs to the organization', function () {
    $admin = createSuperAdmin(['user-view', 'user-destroy']);
    $organization = Organization::factory()->create();
    $uploader = createOrganizationUser($organization, ['media-store']);
    $media = Media::factory()->create(['organization_id' => $organization->id, 'created_by' => $uploader->id]);

    $this->actingAs($admin)->deleteJson("/users/{$uploader->id}", ['password' => 'password'])->assertOk();

    // The person is gone; the organization's menu is not.
    $this->assertDatabaseMissing('users', ['id' => $uploader->id]);
    $this->assertDatabaseHas('media', ['id' => $media->id, 'created_by' => null]);
});

test('the Media page lists photographs and videos alone: an ad’s page is the Ad Builder’s, chosen where it plays', function () {
    // Owner, 2026-10-05: "media library mein show mat karo list lambi ho jayegi ... content library mein show karo agar
    // channel mein use naahi ho rae ho".
    $organization = Organization::factory()->create();
    $actor = createOrganizationUser($organization, ['media-view', 'media-update', 'media-destroy', 'screen-view', 'screen-playlist']);
    $session = ['current_organization_id' => $organization->id];

    $photo = Media::factory()->create(['organization_id' => $organization->id, 'title' => 'Burger']);
    $video = Media::factory()->video()->create(['organization_id' => $organization->id]);
    $page = Media::find(BuilderAd::factory()->withText()->published()->create(['organization_id' => $organization->id])->media_id);
    $platformPage = Media::find(BuilderAd::factory()->withText()->published()->create(['organization_id' => null])->media_id);
    $screen = Screen::factory()->create(['organization_id' => $organization->id]);

    $this->actingAs($actor)->withSession($session);
    $listed = fn (string $query = '') => collect($this->getJson('/media/data'.$query)->assertOk()->json('media'))->pluck('id')->sort()->values()->all();

    // The list knows no ad pages, nor does its type filter.
    expect($page->type)->toBe(Media::TYPE_HTML)
        ->and($listed())->toBe(collect([$photo->id, $video->id])->sort()->values()->all());
    $this->getJson('/media/data?type=html')->assertStatus(422);
    $this->getJson('/media/data?type=audio')->assertStatus(422);

    // Nor is one renamed or deleted here: the Ad Builder names it, unpublishes it and deletes it.
    $this->putJson("/media/{$page->id}", ['title' => 'Renamed'])->assertNotFound();
    $this->deleteJson("/media/{$page->id}")->assertNotFound();
    expect($page->fresh()->title)->not->toBe('Renamed');

    // A screen's Content library offers it while no channel shows it — the organization's own alone, never the platform's
    // (docs/BILLING-SPEC.md §5: a platform ad reaches an organization as a Premium Template).
    $offered = fn () => collect($this->getJson("/screens/{$screen->id}/available-media")->assertOk()->json('media'))->pluck('id')->all();
    expect($offered())->toContain($page->id, $photo->id)->not->toContain($platformPage->id);

    ChannelAd::factory()->create(['channel_id' => Channel::factory()->create(['organization_id' => $organization->id])->id, 'media_id' => $page->id]);
    expect($offered())->not->toContain($page->id)->not->toContain($platformPage->id);

    // Above the organizations the platform's own library is read the same way.
    $this->flushSession();
    $this->actingAs(createSuperAdmin(['media-view']));
    expect(collect($this->getJson('/media/data?library=platform')->assertOk()->json('media'))->pluck('id'))->not->toContain($platformPage->id);
});
