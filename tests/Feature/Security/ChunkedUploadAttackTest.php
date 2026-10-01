<?php

use App\Models\Channel;
use App\Models\Media;
use App\Models\Organization;
use App\Models\Upload;
use App\Services\ChunkedUploads;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Tus;

/*
|--------------------------------------------------------------------------
| Chunked uploads, attacked (docs/UPLOADS-SPEC.md)
|--------------------------------------------------------------------------
|
| The tus endpoints carry bytes for four doors that already guard what a file may be. What is new is the carrying:
| an upload that is somebody else's, offsets and lengths that lie, names that are paths or not text, too many at once,
| an upload posted before it is finished or posted twice, and a program wearing a picture's name. None of it may
| reach a row, a folder outside the uploads, or another person's upload.
|
*/

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('local');
    Storage::fake('uploads');

    $this->organization = Organization::factory()->create(['name' => 'Alpha Mart']);
    $this->manager = createOrganizationUser($this->organization, ['media-view', 'media-store', 'channel-view', 'channel-update', 'ad-store'], 'Manager');
    $this->actingAs($this->manager)->withSession(['current_organization_id' => $this->organization->id]);
});

/** A small real JPEG. */
function smallJpeg(): string
{
    $image = imagecreatetruecolor(64, 64);

    ob_start();
    imagejpeg($image);

    return (string) ob_get_clean();
}

test("somebody else's upload is not found, whatever is done to it", function () {
    $id = Tus::upload($this, smallJpeg(), ['name' => 'mine.jpg', 'purpose' => 'media']);

    $colleague = createOrganizationUser($this->organization, ['media-view', 'media-store'], 'Colleague');
    $this->actingAs($colleague)->withSession(['current_organization_id' => $this->organization->id]);

    Tus::ask($this, $id)->assertNotFound();
    Tus::send($this, $id, 0, 'x')->assertNotFound();
    Tus::giveUp($this, $id)->assertNotFound();
    $this->postJson('/media', ['upload' => $id])
        ->assertStatus(422)->assertJsonValidationErrors(['file' => 'That upload is not finished, or it has expired. Choose the file again.']);

    expect(Upload::whereKey($id)->exists())->toBeTrue()->and(Media::count())->toBe(0);
});

test('an upload is posted only when finished, only for what it was opened for, and only once', function () {
    $half = Tus::idOf(Tus::open($this, 100, ['name' => 'half.jpg', 'purpose' => 'media']));
    Tus::send($this, $half, 0, str_repeat('a', 50))->assertNoContent();
    $this->postJson('/media', ['upload' => $half])->assertStatus(422)->assertJsonValidationErrors('file');

    // Opened for the shelf, posted to the library.
    $shelf = Tus::upload($this, smallJpeg(), ['name' => 'logo.jpg', 'purpose' => 'asset']);
    $this->postJson('/media', ['upload' => $shelf])->assertStatus(422)->assertJsonValidationErrors('file');

    $id = Tus::upload($this, smallJpeg(), ['name' => 'menu.jpg', 'purpose' => 'media']);

    // A second request naming it while the first is making its row is refused.
    $lock = Cache::lock('upload-finish:'.$id, 120);
    $lock->get();
    $this->postJson('/media', ['upload' => $id])
        ->assertStatus(422)->assertJsonValidationErrors(['file' => 'This file is being added already. Wait a moment.']);
    $lock->release();

    $this->postJson('/media', ['upload' => $id])->assertOk();
    $this->postJson('/media', ['upload' => $id])->assertStatus(422)->assertJsonValidationErrors('file');

    // A file and an upload together: which one? Neither.
    $other = Tus::upload($this, smallJpeg(), ['name' => 'b.jpg', 'purpose' => 'media']);
    $this->post('/media', ['upload' => $other, 'file' => UploadedFile::fake()->image('c.jpg')], ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonValidationErrors(['file' => 'Send the file or an upload, not both.']);

    expect(Media::count())->toBe(1);
});

test('an id that is not an upload is a refusal, never an error', function () {
    foreach (['', 'nope', '../../etc/passwd', str_repeat('a', 5000), '00000000-0000-0000-0000-000000000000'] as $id) {
        $this->postJson('/media', ['upload' => $id])->assertStatus(422);
    }

    $this->postJson('/media', ['upload' => ['x']])->assertStatus(422);
    $this->call('HEAD', '/uploads/not-a-uuid', [], [], [], ['HTTP_TUS_RESUMABLE' => '1.0.0'])->assertNotFound();
});

test('a length that lies is caught: more than declared, a chunk that is not what it says', function () {
    $id = Tus::idOf(Tus::open($this, 100, ['name' => 'a.jpg', 'purpose' => 'media']));

    // More than the file said it was, declared or not.
    Tus::send($this, $id, 0, str_repeat('a', 150))->assertStatus(413);
    Tus::send($this, $id, 0, str_repeat('a', 150), ['Content-Length' => ''])->assertStatus(413);

    // A chunk shorter than its Content-Length is sent again, and leaves nothing behind.
    Tus::send($this, $id, 0, str_repeat('a', 40), ['Content-Length' => '60'])->assertStatus(503);

    Tus::ask($this, $id)->assertHeader('Upload-Offset', '0');
    expect(Storage::disk('uploads')->get("{$id}.part"))->toBe('');

    // An offset that is not a number, and one far past the end.
    Tus::send($this, $id, 0, 'a', ['Upload-Offset' => '-1'])->assertStatus(400);
    Tus::send($this, $id, 999999, 'a')->assertStatus(409);
});

test('a name is only a name: no folder, no control characters, readable text, and not too long', function () {
    $id = Tus::upload($this, smallJpeg(), ['name' => '../../../../etc/passwd.jpg', 'purpose' => 'media']);
    expect(Upload::find($id)->filename)->toBe('passwd.jpg');

    $id = Tus::upload($this, smallJpeg(), ['name' => 'C:\\Windows\\win.jpg', 'purpose' => 'media']);
    expect(Upload::find($id)->filename)->toBe('win.jpg');

    // A part is named by its upload, never by the file's name, and lands nowhere else.
    expect(collect(Storage::disk('uploads')->allFiles())->every(fn (string $file) => preg_match('/^[0-9a-f-]{36}\.part$/', $file) === 1))->toBeTrue()
        ->and(Storage::disk('local')->allFiles())->toBe([]);

    foreach (["bad\x00name.jpg", "tab\tname.jpg", "\xC3\x28bad.jpg", str_repeat('a', 256).'.jpg', '   '] as $name) {
        Tus::open($this, 10, ['name' => $name, 'purpose' => 'media'])->assertStatus(422);
    }
});

test('the description is small and readable', function () {
    Tus::open($this, 10, [], ['Upload-Metadata' => 'name not-base64!!,purpose '.base64_encode('media')])->assertStatus(400);
    Tus::open($this, 10, [], ['Upload-Metadata' => 'name '.base64_encode(str_repeat('a', 5000).'.jpg')])->assertStatus(400);

    // A key it does not know is kept but never read.
    Tus::open($this, 10, ['name' => 'a.jpg', 'purpose' => 'media', 'role' => 'Super-Admin', 'organization_id' => 999])->assertCreated();
});

test('nobody opens more than twenty at once', function () {
    foreach (range(1, ChunkedUploads::MAX_OPEN_PER_USER) as $n) {
        Tus::open($this, 10, ['name' => "{$n}.jpg", 'purpose' => 'media'])->assertCreated();
    }

    Tus::open($this, 10, ['name' => 'one-too-many.jpg', 'purpose' => 'media'])
        ->assertStatus(429)->assertJsonPath('message', 'Too many uploads at once: wait for some to finish, or cancel some.');
});

test("an organization's person uploads into the organization they stand in, whatever the upload says", function () {
    $other = Organization::factory()->create();

    $id = Tus::idOf(Tus::open($this, 10, ['name' => 'a.jpg', 'purpose' => 'media', 'library' => $other->id]));
    expect(Upload::find($id)->organization_id)->toBe($this->organization->id);

    $id = Tus::idOf(Tus::open($this, 10, ['name' => 'a.jpg', 'purpose' => 'asset', 'organization' => $other->id]));
    expect(Upload::find($id)->organization_id)->toBe($this->organization->id);

    // And a channel of another organization is not found at all.
    $theirs = Channel::factory()->create(['organization_id' => $other->id]);
    Tus::open($this, 10, ['name' => 'a.jpg', 'purpose' => 'channel', 'channel' => $theirs->id])->assertNotFound();
});

test('a program wearing a picture\'s name is read by its bytes when it is added, and refused', function () {
    $id = Tus::upload($this, "<?php echo 'pwned'; ?>", ['name' => 'shell.jpg', 'type' => 'image/jpeg', 'purpose' => 'media']);

    $this->postJson('/media', ['upload' => $id])
        ->assertStatus(422)->assertJsonValidationErrors(['file' => 'Only images (JPG, PNG, GIF, WEBP) and videos (MP4, WEBM) can be uploaded.']);

    expect(Media::count())->toBe(0)->and(Storage::disk('public')->allFiles())->toBe([]);
});

test('an upload that has expired can be neither continued nor posted', function () {
    $id = Tus::idOf(Tus::open($this, 10, ['name' => 'a.jpg', 'purpose' => 'media']));
    Tus::send($this, $id, 0, '12345')->assertNoContent();

    $this->travel(ChunkedUploads::LIFETIME_HOURS + 1)->hours();

    Tus::send($this, $id, 5, '12345')->assertNotFound();
    Tus::ask($this, $id)->assertNotFound();
    $this->postJson('/media', ['upload' => $id])->assertStatus(422);
});
