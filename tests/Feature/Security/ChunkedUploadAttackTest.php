<?php

use App\Models\BuilderAsset;
use App\Models\Channel;
use App\Models\Media;
use App\Models\Organization;
use App\Models\Upload;
use App\Models\User;
use App\Services\ChunkedUploads;
use App\Services\DiskGuard;
use App\Services\OrganizationStorage;
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

test('the type the browser claims is kept only as a plain type that fits its column, whatever its length', function () {
    // uploads.file_type holds 100 characters: a longer type, before its slash or after it, was a 500 on MySQL's strict mode.
    foreach ([str_repeat('a', 300).'/jpeg', 'image/'.str_repeat('b', 81), 'image', 'IMAGE/JPEG', "image/jpeg\n"] as $type) {
        $id = Tus::idOf(Tus::open($this, 10, ['name' => 'a.jpg', 'type' => $type, 'purpose' => 'media']));

        expect(Upload::find($id)->file_type)->toBeNull();
    }

    $longest = str_repeat('a', 19).'/'.str_repeat('b', 80);
    $id = Tus::idOf(Tus::open($this, 10, ['name' => 'a.jpg', 'type' => $longest, 'purpose' => 'media']));

    expect(Upload::find($id)->file_type)->toBe($longest)->and(strlen($longest))->toBe(100);
});

test('above the organizations, a finished upload joins only the library or the shelf it was opened for', function () {
    $this->actingAs(createSuperAdmin());
    session()->forget('current_organization_id');
    $bravo = Organization::factory()->create(['name' => 'Bravo Foods']);

    // Opened for the platform's library and posted for Bravo's (a file dropped again on another library carried on the upload a
    // reload had cut short): refused, nothing lands in Bravo's library, and the upload goes — nothing will add it where it was
    // opened for, and the file chosen again makes an upload of its own.
    $id = Tus::upload($this, smallJpeg(), ['name' => 'menu.jpg', 'purpose' => 'media', 'library' => 'platform']);
    $this->postJson('/media', ['upload' => $id, 'organization_id' => $bravo->id])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file' => 'This file was uploaded for another library. Choose it again to add it here.'])
        ->assertJsonPath('upload_given_up', true);
    expect(Media::count())->toBe(0)->and(Upload::whereKey($id)->exists())->toBeFalse()
        ->and(Storage::disk('uploads')->exists("{$id}.part"))->toBeFalse();

    // Posted for the library it was opened for, an upload joins it.
    $id = Tus::upload($this, smallJpeg(), ['name' => 'menu.jpg', 'purpose' => 'media', 'library' => 'platform']);
    $this->postJson('/media', ['upload' => $id])->assertOk();
    expect(Media::sole()->organization_id)->toBeNull();

    // And the other way: dropped on Bravo's, posted for the platform's.
    $id = Tus::upload($this, smallJpeg(), ['name' => 'deal.jpg', 'purpose' => 'media', 'library' => (string) $bravo->id]);
    $this->postJson('/media', ['upload' => $id])
        ->assertStatus(422)->assertJsonValidationErrors(['file' => 'This file was uploaded for another library. Choose it again to add it here.']);
    $id = Tus::upload($this, smallJpeg(), ['name' => 'deal.jpg', 'purpose' => 'media', 'library' => (string) $bravo->id]);
    $this->postJson('/media', ['upload' => $id, 'organization_id' => $bravo->id])->assertOk();
    expect(Media::where('organization_id', $bravo->id)->count())->toBe(1);

    // The Ad Builder's shelf: dropped on Bravo's files, posted for the platform's, and back.
    $id = Tus::upload($this, smallJpeg(), ['name' => 'logo.jpg', 'purpose' => 'asset', 'organization' => (string) $bravo->id]);
    $this->postJson('/builder/assets', ['upload' => $id])
        ->assertStatus(422)->assertJsonPath('upload_given_up', true)
        ->assertJsonValidationErrors(['file' => 'This file was uploaded for another owner. Choose it again to add it here.']);
    expect(BuilderAsset::count())->toBe(0)->and(Upload::whereKey($id)->exists())->toBeFalse();
    $id = Tus::upload($this, smallJpeg(), ['name' => 'logo.jpg', 'purpose' => 'asset', 'organization' => (string) $bravo->id]);
    $this->postJson('/builder/assets', ['upload' => $id, 'organization_id' => $bravo->id])->assertOk();

    $id = Tus::upload($this, smallJpeg(), ['name' => 'badge.jpg', 'purpose' => 'asset']);
    $this->postJson('/builder/assets', ['upload' => $id, 'organization_id' => $bravo->id])
        ->assertStatus(422)->assertJsonValidationErrors(['file' => 'This file was uploaded for another owner. Choose it again to add it here.']);
    $id = Tus::upload($this, smallJpeg(), ['name' => 'badge.jpg', 'purpose' => 'asset']);
    $this->postJson('/builder/assets', ['upload' => $id])->assertOk();

    expect(BuilderAsset::where('organization_id', $bravo->id)->pluck('title')->all())->toBe(['logo'])
        ->and(BuilderAsset::whereNull('organization_id')->pluck('title')->all())->toBe(['badge'])
        ->and(Upload::count())->toBe(0);
});

test('an upload is not given up while a door is making its row from it, nor taken by the prune', function () {
    $id = Tus::upload($this, smallJpeg(), ['name' => 'menu.jpg', 'purpose' => 'media']);

    // A door holds it (its save is reading the file): the box's × waits, and the bytes stay under the save.
    $lock = ChunkedUploads::finishLock($id);
    $lock->get();
    Tus::giveUp($this, $id)->assertStatus(409)->assertJsonPath('message', 'This file is being added. Wait a moment.');
    expect(Upload::whereKey($id)->exists())->toBeTrue()->and(Storage::disk('uploads')->exists("{$id}.part"))->toBeTrue();

    // Past its end while the door still has it (it found the upload a moment before): uploads:prune leaves it to the door, or to
    // its next run.
    Upload::whereKey($id)->update(['expires_at' => now()->subSecond()]);
    $this->artisan('uploads:prune')->expectsOutputToContain('0 upload(s) removed.')->assertSuccessful();
    expect(Upload::whereKey($id)->exists())->toBeTrue()->and(Storage::disk('uploads')->exists("{$id}.part"))->toBeTrue();

    $lock->release();
    $this->artisan('uploads:prune')->expectsOutputToContain('1 upload(s) removed.')->assertSuccessful();
    expect(Upload::whereKey($id)->exists())->toBeFalse();

    // With nobody holding it, × gives it up at once.
    $other = Tus::upload($this, smallJpeg(), ['name' => 'deal.jpg', 'purpose' => 'media']);
    Tus::giveUp($this, $other)->assertNoContent();
    expect(Upload::whereKey($other)->exists())->toBeFalse()->and(Storage::disk('uploads')->exists("{$other}.part"))->toBeFalse();
});

test('a door that finds its upload given up once it holds it says it is gone, and makes nothing', function () {
    $id = Tus::upload($this, smallJpeg(), ['name' => 'menu.jpg', 'purpose' => 'media']);

    // The box's × lands between the door's first look and its lock.
    app()->instance(ChunkedUploads::class, new class(app(OrganizationStorage::class), app(DiskGuard::class)) extends ChunkedUploads
    {
        public int $looks = 0;

        public function finished(User $user, string $id, string $purpose): ?Upload
        {
            $found = parent::finished($user, $id, $purpose);

            if (++$this->looks === 1 && $found !== null) {
                $this->cancel($found);
            }

            return $found;
        }
    });

    $this->postJson('/media', ['upload' => $id])
        ->assertStatus(422)->assertJsonPath('upload_given_up', true)
        ->assertJsonValidationErrors(['file' => 'That upload is not finished, or it has expired. Choose the file again.']);
    expect(Media::count())->toBe(0)->and(app(ChunkedUploads::class)->looks)->toBe(2);
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
