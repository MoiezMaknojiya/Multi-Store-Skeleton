<?php

use App\Models\Media;
use App\Models\Store;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| The awkward shapes an upload can arrive in
|--------------------------------------------------------------------------
|
| The happy path is covered in MediaCrudTest. These are the inputs a browser
| would rarely produce but a client can send anyway — a filename is just a
| string in a multipart header, and nothing stops it being absurd.
|
*/

test('an absurdly long filename becomes a usable title instead of a database error', function () {
    Storage::fake('public');
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['media-store']);

    // A real filesystem caps names near 255, but a crafted request is not bound
    // by that. Untrimmed this lands in a varchar(255) column and the shop owner
    // gets a 500 rather than a file in their library.
    $name = str_repeat('a', 400).'.jpg';

    $this->actingAs($actor)
        ->withSession(['current_store_id' => $store->id])
        ->post('/media', ['file' => UploadedFile::fake()->image($name)])
        ->assertOk();

    $title = Media::firstOrFail()->title;
    expect(mb_strlen($title))->toBe(255);
    expect($title)->toStartWith('aaa');
});

test('a file with no name of its own still gets a title', function () {
    Storage::fake('public');
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['media-store']);

    // ".jpg" has an extension and nothing else, so the name-without-extension is
    // empty. A blank row in the library is a row nobody can identify or search.
    $this->actingAs($actor)
        ->withSession(['current_store_id' => $store->id])
        ->post('/media', ['file' => UploadedFile::fake()->image('.jpg')])
        ->assertOk();

    expect(Media::firstOrFail()->title)->toBe('Untitled');
});

test('a title of nothing but spaces falls back to the file name', function () {
    Storage::fake('public');
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['media-store']);

    $this->actingAs($actor)
        ->withSession(['current_store_id' => $store->id])
        ->post('/media', [
            'file' => UploadedFile::fake()->image('breakfast.jpg'),
            'title' => '   ',
        ])
        ->assertOk();

    expect(Media::firstOrFail()->title)->toBe('breakfast');
});

test('a title the owner actually typed is kept, spaces trimmed', function () {
    Storage::fake('public');
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['media-store']);

    $this->actingAs($actor)
        ->withSession(['current_store_id' => $store->id])
        ->post('/media', [
            'file' => UploadedFile::fake()->image('ignored.jpg'),
            'title' => '  Breakfast Board  ',
        ])
        ->assertOk();

    expect(Media::firstOrFail()->title)->toBe('Breakfast Board');
});

test('a typed title longer than the column is refused, not silently cut', function () {
    Storage::fake('public');
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['media-store']);

    // Deliberately different from the filename fallback above: what somebody
    // TYPED should come back as an error they can see and fix, not be quietly
    // shortened behind their back.
    $this->actingAs($actor)
        ->withSession(['current_store_id' => $store->id])
        ->postJson('/media', [
            'file' => UploadedFile::fake()->image('menu.jpg'),
            'title' => str_repeat('b', 256),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['title']);

    expect(Media::count())->toBe(0);
});

test('a file bigger than the limit is refused with a message about the size', function () {
    Storage::fake('public');
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['media-store']);

    // The cap is declared in StoreMediaRequest but was never exercised, so
    // nothing proved the limit or its wording actually reached anyone.
    $this->actingAs($actor)
        ->withSession(['current_store_id' => $store->id])
        ->postJson('/media', [
            'file' => UploadedFile::fake()->create('feature.mp4', 300_000, 'video/mp4'),
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file' => 'The file may not be larger than 250 MB.']);

    expect(Media::count())->toBe(0);
});

test('a file right on the limit is still accepted', function () {
    Storage::fake('public');
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['media-store']);

    // The boundary matters as much as the refusal: an off-by-one here would turn
    // away files the product promises to take.
    $this->actingAs($actor)
        ->withSession(['current_store_id' => $store->id])
        ->post('/media', [
            'file' => UploadedFile::fake()->create('feature.mp4', 256_000, 'video/mp4'),
        ])
        ->assertOk();

    expect(Media::count())->toBe(1);
});

test('an empty file is refused', function () {
    Storage::fake('public');
    $store = Store::factory()->create();
    $actor = createStoreUser($store, ['media-store']);

    // A REAL empty file, not UploadedFile::fake() — the fake declares its own
    // mime type from the extension, which is exactly the check being tested here.
    // A genuine upload has its type read from the CONTENT, and empty content has
    // no type at all, so it never becomes a library entry.
    $path = tempnam(sys_get_temp_dir(), 'empty');
    file_put_contents($path, '');

    try {
        $this->actingAs($actor)
            ->withSession(['current_store_id' => $store->id])
            ->postJson('/media', ['file' => new UploadedFile($path, 'empty.jpg', null, null, true)])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    } finally {
        @unlink($path);
    }

    expect(Media::count())->toBe(0);
});
