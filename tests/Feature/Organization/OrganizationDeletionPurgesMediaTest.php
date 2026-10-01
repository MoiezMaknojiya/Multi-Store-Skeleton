<?php

use App\Models\Media;
use App\Models\PlaylistItem;
use App\Models\Role;
use App\Models\Screen;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Deleting an organization deletes its whole media library
|--------------------------------------------------------------------------
|
| Every row, whoever uploaded it, and every file behind them — off the disk as well
| (owner's rule). A row without its file is a broken thumbnail on somebody's screen; a
| file without its row is litter nobody can ever find again.
|
*/

beforeEach(function () {
    Storage::fake('public');
});

/** A library file with real bytes behind it, so its removal can be seen on disk. */
function libraryFile(Organization $organization, ?User $uploader = null): Media
{
    $media = Media::factory()->create([
        'organization_id' => $organization->id,
        'created_by' => $uploader?->id,
        'path' => "media/{$organization->id}/".Str::ulid().'.jpg',
        'thumbnail_path' => "media/{$organization->id}/thumbs/".Str::ulid().'.jpg',
    ]);

    Storage::disk('public')->put($media->path, 'image');
    Storage::disk('public')->put($media->thumbnail_path, 'thumb');

    return $media;
}

function deleteOrganizationAsPlatform(Organization $organization)
{
    return test()->actingAs(createSuperAdmin(['organization-destroy']))
        ->deleteJson("/organizations/{$organization->id}", ['confirm_name' => $organization->name, 'password' => 'password']);
}

test('deleting an organization removes its whole library, rows and files, whoever uploaded them', function () {
    $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
    $files = collect([
        libraryFile($organization, User::factory()->create()),
        libraryFile($organization, User::factory()->create()),
        libraryFile($organization),
    ]);

    deleteOrganizationAsPlatform($organization)->assertOk();

    expect(Media::where('organization_id', $organization->id)->count())->toBe(0);
    $files->each(function (Media $media) {
        Storage::disk('public')->assertMissing($media->path);
        Storage::disk('public')->assertMissing($media->thumbnail_path);
    });
    $this->assertDatabaseMissing('organizations', ['id' => $organization->id]);
});

test("another organization's library is left exactly as it was", function () {
    $doomed = Organization::factory()->create();
    $neighbour = Organization::factory()->create();
    libraryFile($doomed);
    $kept = libraryFile($neighbour);

    deleteOrganizationAsPlatform($doomed)->assertOk();

    expect(Media::find($kept->id))->not->toBeNull();
    Storage::disk('public')->assertExists($kept->path);
    Storage::disk('public')->assertExists($kept->thumbnail_path);
});

test('the playlists that played those files go with the screens', function () {
    $organization = Organization::factory()->create();
    $poster = libraryFile($organization);
    $screen = Screen::factory()->create(['organization_id' => $organization->id, 'default_media_id' => $poster->id]);
    PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $poster->id, 'position' => 0, 'duration_seconds' => 10]);

    deleteOrganizationAsPlatform($organization)->assertOk();

    expect(Screen::find($screen->id))->toBeNull()
        ->and(PlaylistItem::where('screen_id', $screen->id)->count())->toBe(0);
});

test('the log says how many screens and files went with the organization', function () {
    $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
    libraryFile($organization);
    libraryFile($organization);

    deleteOrganizationAsPlatform($organization)->assertOk();

    $this->assertDatabaseHas('activity_logs', [
        'action' => 'organization.deleted', 'description' => 'Deleted organization Alpha Mart with its 0 screens and 2 media files',
    ]);
});

test('deleting an account never touches an organization’s library', function () {
    $organization = Organization::factory()->create();
    createOrganizationMember($organization, Role::OWNER);
    $uploader = createOrganizationMember($organization, Role::STAFF);
    $poster = libraryFile($organization, $uploader);

    $this->actingAs(createSuperAdmin(['user-view', 'user-destroy']))->deleteJson("/users/{$uploader->id}", ['password' => 'password'])->assertOk();

    expect(Media::find($poster->id))->not->toBeNull();
    Storage::disk('public')->assertExists($poster->path);
});

test('if the delete is rolled back, every file is still there', function () {
    // The files go only once the delete is committed. A rollback must never leave rows
    // pointing at files that are already gone.
    $organization = Organization::factory()->create();
    $poster = libraryFile($organization);

    try {
        DB::transaction(function () use ($organization) {
            $organization->delete();

            throw new RuntimeException('something later in the same request failed');
        });
    } catch (RuntimeException) {
        // Expected.
    }

    expect(Organization::find($organization->id))->not->toBeNull();
    expect(Media::find($poster->id))->not->toBeNull();
    Storage::disk('public')->assertExists($poster->path);
    Storage::disk('public')->assertExists($poster->thumbnail_path);
});
