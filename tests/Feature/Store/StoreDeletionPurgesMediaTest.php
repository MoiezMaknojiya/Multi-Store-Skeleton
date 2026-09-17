<?php

use App\Models\Media;
use App\Models\PlaylistItem;
use App\Models\Role;
use App\Models\Screen;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Deleting a shop deletes its whole media library
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
function libraryFile(Store $store, ?User $uploader = null): Media
{
    $media = Media::factory()->create([
        'store_id' => $store->id,
        'created_by' => $uploader?->id,
        'path' => "media/{$store->id}/".Str::ulid().'.jpg',
        'thumbnail_path' => "media/{$store->id}/thumbs/".Str::ulid().'.jpg',
    ]);

    Storage::disk('public')->put($media->path, 'image');
    Storage::disk('public')->put($media->thumbnail_path, 'thumb');

    return $media;
}

function deleteStoreAsPlatform(Store $store)
{
    return test()->actingAs(createSuperAdmin(['store-destroy']))
        ->deleteJson("/stores/{$store->id}", ['confirm_name' => $store->name, 'password' => 'password']);
}

test('deleting a store removes its whole library, rows and files, whoever uploaded them', function () {
    $store = Store::factory()->create(['name' => 'Alpha Mart']);
    $files = collect([
        libraryFile($store, User::factory()->create()),
        libraryFile($store, User::factory()->create()),
        libraryFile($store),
    ]);

    deleteStoreAsPlatform($store)->assertOk();

    expect(Media::where('store_id', $store->id)->count())->toBe(0);
    $files->each(function (Media $media) {
        Storage::disk('public')->assertMissing($media->path);
        Storage::disk('public')->assertMissing($media->thumbnail_path);
    });
    $this->assertDatabaseMissing('stores', ['id' => $store->id]);
});

test("another store's library is left exactly as it was", function () {
    $doomed = Store::factory()->create();
    $neighbour = Store::factory()->create();
    libraryFile($doomed);
    $kept = libraryFile($neighbour);

    deleteStoreAsPlatform($doomed)->assertOk();

    expect(Media::find($kept->id))->not->toBeNull();
    Storage::disk('public')->assertExists($kept->path);
    Storage::disk('public')->assertExists($kept->thumbnail_path);
});

test('the playlists that played those files go with the screens', function () {
    $store = Store::factory()->create();
    $poster = libraryFile($store);
    $screen = Screen::factory()->create(['store_id' => $store->id, 'default_media_id' => $poster->id]);
    PlaylistItem::create(['screen_id' => $screen->id, 'media_id' => $poster->id, 'position' => 0, 'duration_seconds' => 10]);

    deleteStoreAsPlatform($store)->assertOk();

    expect(Screen::find($screen->id))->toBeNull()
        ->and(PlaylistItem::where('screen_id', $screen->id)->count())->toBe(0);
});

test('the log says how many screens and files went with the store', function () {
    $store = Store::factory()->create(['name' => 'Alpha Mart']);
    libraryFile($store);
    libraryFile($store);

    deleteStoreAsPlatform($store)->assertOk();

    $this->assertDatabaseHas('activity_logs', [
        'action' => 'store.deleted', 'description' => 'Deleted store Alpha Mart with its 0 screens and 2 media files',
    ]);
});

test('deleting an account never touches a store’s library', function () {
    $store = Store::factory()->create();
    createStoreMember($store, Role::OWNER);
    $uploader = createStoreMember($store, Role::STAFF);
    $poster = libraryFile($store, $uploader);

    $this->actingAs(createSuperAdmin(['user-view', 'user-destroy']))->deleteJson("/users/{$uploader->id}", ['password' => 'password'])->assertOk();

    expect(Media::find($poster->id))->not->toBeNull();
    Storage::disk('public')->assertExists($poster->path);
});

test('if the delete is rolled back, every file is still there', function () {
    // The files go only once the delete is committed. A rollback must never leave rows
    // pointing at files that are already gone.
    $store = Store::factory()->create();
    $poster = libraryFile($store);

    try {
        DB::transaction(function () use ($store) {
            $store->delete();

            throw new RuntimeException('something later in the same request failed');
        });
    } catch (RuntimeException) {
        // Expected.
    }

    expect(Store::find($store->id))->not->toBeNull();
    expect(Media::find($poster->id))->not->toBeNull();
    Storage::disk('public')->assertExists($poster->path);
    Storage::disk('public')->assertExists($poster->thumbnail_path);
});
