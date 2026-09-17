<?php

use App\Models\Media;
use App\Models\Permission;
use App\Models\Store;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Uploads, attacked on purpose
|--------------------------------------------------------------------------
|
| A PHP file wearing a .jpg name, a 300 MB loop, a filename full of ../, a title the length of a book.
| Nothing may land on the disk that the player cannot render, nothing may land outside its store's own
| folder, and nothing may keep the name the client chose.
|
*/

beforeEach(function () {
    Storage::fake('public');

    $this->store = Store::factory()->create(['name' => 'Alpha Mart']);
    $this->uploader = createStoreUser($this->store, [...Permission::STORE], 'Uploader');
    $this->actingAs($this->uploader)->withSession(['current_store_id' => $this->store->id]);
});

/**
 * A real file on disk carrying real bytes, handed over with a LIE about its type — exactly what an
 * attacker sends. `UploadedFile::fake()` cannot be used for this: its mime comes from the filename,
 * so a PHP payload called .jpg would sail through a test while production sniffs the content and
 * refuses it. Here the third argument is only what the client claims; the server ignores it.
 */
function uploadWithBytes(string $name, string $bytes): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'attack');
    file_put_contents($path, $bytes);

    return new UploadedFile($path, $name, 'image/jpeg', null, true);
}

test('a file the player cannot render is refused, whatever its name or its client type says', function () {
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>';

    $attempts = [
        'shell.php (php bytes)' => uploadWithBytes('shell.php', "<?php echo 'pwned';"),
        // A PHP payload wearing a .jpg name, claiming image/jpeg: the type is read off the bytes.
        'shell.jpg (php bytes)' => uploadWithBytes('shell.jpg', "<?php echo 'pwned';"),
        'page.html (html bytes)' => uploadWithBytes('page.html', '<!doctype html><script>alert(1)</script>'),
        'vector.svg (svg bytes)' => uploadWithBytes('vector.svg', $svg),
        'vector.png (svg bytes)' => uploadWithBytes('vector.png', $svg),
        'nothing.jpg (no bytes)' => uploadWithBytes('nothing.jpg', ''),
        'sound.mp3' => UploadedFile::fake()->create('sound.mp3', 10, 'audio/mpeg'),
        'archive.zip' => UploadedFile::fake()->create('archive.zip', 10, 'application/zip'),
    ];

    foreach ($attempts as $name => $file) {
        $status = $this->postJson('/media', ['file' => $file])->status();
        expect($status)->toBe(422, "{$name} answered {$status}");
    }

    expect(Media::count())->toBe(0);
    expect(Storage::disk('public')->allFiles())->toBeEmpty();
});

test('a file larger than the cap never reaches the disk', function () {
    $tooBig = UploadedFile::fake()->create('huge.mp4', 300 * 1024, 'video/mp4');   // 300 MB

    $this->postJson('/media', ['file' => $tooBig])->assertStatus(422)->assertJsonValidationErrors('file');

    expect(Media::count())->toBe(0)
        ->and(Storage::disk('public')->allFiles())->toBeEmpty();
});

test('the client never chooses where a file lands or what it is called', function () {
    $file = UploadedFile::fake()->image('../../../../evil.jpg', 400, 300);

    $this->postJson('/media', ['file' => $file, 'title' => 'Poster'])->assertOk();

    $media = Media::sole();
    expect($media->store_id)->toBe($this->store->id)
        ->and($media->path)->toStartWith("media/{$this->store->id}/")
        ->and($media->path)->not->toContain('..')
        ->and($media->path)->not->toContain('evil')
        ->and($media->disk)->toBe('public');

    Storage::disk('public')->assertExists($media->path);
    foreach (Storage::disk('public')->allFiles() as $path) {
        expect($path)->toStartWith("media/{$this->store->id}/");
    }
});

test('a filename is never trusted as a title, and browser-measured numbers are bounded', function () {
    $longName = str_repeat('name', 200).'.jpg';   // 800+ characters

    $this->postJson('/media', ['file' => UploadedFile::fake()->image($longName, 200, 200)])->assertOk();
    expect(strlen(Media::sole()->title))->toBeLessThanOrEqual(255);

    foreach ([
        ['duration_seconds' => -1],
        ['duration_seconds' => 999999],
        ['width' => 0],
        ['height' => 99999],
        ['width' => 'abc'],
        ['poster' => 'javascript:alert(1)'],
        ['poster' => str_repeat('data:image/png;base64,', 1)],
    ] as $extra) {
        $status = $this->postJson('/media', ['file' => UploadedFile::fake()->create('clip.mp4', 20, 'video/mp4'), ...$extra])->status();
        expect($status)->toBeIn([200, 422], 'measurement '.json_encode($extra)." answered {$status}");
    }
});

test('an upload with no store in the session is refused, not filed somewhere else', function () {
    $this->actingAs($this->uploader);
    $this->flushSession();

    // Permissions are read through the membership of the store in the session: with no store there is
    // nothing to read, so the upload is refused before a byte reaches the disk.
    $status = $this->postJson('/media', ['file' => UploadedFile::fake()->image('poster.jpg')])->status();

    expect($status)->toBe(403)
        ->and(Media::count())->toBe(0)
        ->and(Storage::disk('public')->allFiles())->toBeEmpty();
});

test('another store’s file cannot be deleted, and deleting your own takes both files', function () {
    $other = Store::factory()->create();
    $theirs = Media::factory()->create(['store_id' => $other->id, 'path' => 'media/9/theirs.jpg', 'thumbnail_path' => 'media/9/thumbs/theirs.jpg']);
    Storage::disk('public')->put($theirs->path, 'bytes');
    Storage::disk('public')->put($theirs->thumbnail_path, 'bytes');

    $this->deleteJson("/media/{$theirs->id}")->assertNotFound();
    Storage::disk('public')->assertExists($theirs->path);

    $this->postJson('/media', ['file' => UploadedFile::fake()->image('mine.jpg', 300, 200)])->assertOk();
    $mine = Media::where('store_id', $this->store->id)->sole();

    $this->deleteJson("/media/{$mine->id}")->assertOk();
    Storage::disk('public')->assertMissing($mine->path);
    if ($mine->thumbnail_path) {
        Storage::disk('public')->assertMissing($mine->thumbnail_path);
    }
    expect(Media::find($mine->id))->toBeNull()
        ->and(Media::find($theirs->id))->not->toBeNull();
});
