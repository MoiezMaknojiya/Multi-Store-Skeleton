<?php

use App\Models\BuilderAsset;
use App\Models\Campaign;
use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Media;
use App\Models\Organization;
use App\Models\Upload;
use App\Services\DiskGuard;
use App\Services\OrganizationStorage;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Tus;
use Tests\Support\VideoFiles;

/*
|--------------------------------------------------------------------------
| A file sent in chunks (docs/UPLOADS-SPEC.md, owner 2026-09-29)
|--------------------------------------------------------------------------
|
| Every page that takes a file sends it by the tus protocol: opened with its size and what it is for, sent 5 MB at
| a time, and handed to the form it was chosen for, which makes its row through the same door as ever. Everything
| the door would refuse is refused when the upload is opened, before a byte.
|
*/

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('uploads');

    $this->organization = Organization::factory()->create(['name' => 'Alpha Mart']);
    $this->manager = createOrganizationUser($this->organization, [
        'media-view', 'media-store', 'channel-view', 'channel-update', 'ad-view', 'ad-store',
    ], 'Manager');
    $this->actingAs($this->manager)->withSession(['current_organization_id' => $this->organization->id]);
});

/** A real JPEG, $width wide, as a phone would send one. */
function jpegBytes(int $width = 640, int $height = 360): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 30, 120, 200));

    ob_start();
    imagejpeg($image, null, 90);

    return (string) ob_get_clean();
}

test('a file sent in chunks joins the library like one posted whole, and its upload is forgotten', function () {
    $bytes = jpegBytes();
    $id = Tus::upload($this, $bytes, ['name' => 'Menu board.jpg', 'type' => 'image/jpeg', 'purpose' => 'media'], 4096);

    Tus::ask($this, $id)->assertOk()
        ->assertHeader('Upload-Offset', (string) strlen($bytes))
        ->assertHeader('Upload-Length', (string) strlen($bytes))
        ->assertHeader('Tus-Resumable', '1.0.0');

    $this->postJson('/media', ['upload' => $id])->assertOk()->assertJsonPath('media.title', 'Menu board');

    $media = Media::sole();
    expect($media->organization_id)->toBe($this->organization->id)
        ->and($media->size)->toBe(strlen($bytes))
        ->and($media->thumbnail_path)->not->toBeNull()
        ->and(Storage::disk('public')->get($media->path))->toBe($bytes)
        ->and(Upload::count())->toBe(0)
        ->and(Storage::disk('uploads')->allFiles())->toBe([]);
});

test('every door takes a finished upload: a channel, the ads network and the Ad Builder shelf', function () {
    $channel = Channel::factory()->create(['organization_id' => $this->organization->id]);

    $id = Tus::upload($this, jpegBytes(), ['name' => 'deal.jpg', 'purpose' => 'channel', 'channel' => $channel->id]);
    $this->postJson("/channels/{$channel->id}/ads", ['upload' => $id, 'seconds' => 8])->assertOk();

    $id = Tus::upload($this, jpegBytes(320, 320), ['name' => 'logo.jpg', 'purpose' => 'asset']);
    $this->postJson('/builder/assets', ['upload' => $id])->assertOk();

    $this->actingAs(createSuperAdmin())->withSession([]);
    $id = Tus::upload($this, jpegBytes(1920, 1080), ['name' => 'cola.jpg', 'purpose' => 'campaign']);
    $this->postJson('/campaigns', [
        'upload' => $id, 'name' => 'Cola', 'advertiser_name' => 'Cola Co', 'duration_seconds' => 10,
        'is_active' => true, 'screen_ids' => [],
    ])->assertOk();

    expect(ChannelAd::sole()->media->organization_id)->toBe($this->organization->id)
        ->and(BuilderAsset::sole()->organization_id)->toBe($this->organization->id)
        ->and(Campaign::sole()->name)->toBe('Cola')
        ->and(Upload::count())->toBe(0);
});

test('a video is measured from its bytes when it is added: one too long is refused, and the upload waits', function () {
    $id = Tus::upload($this, VideoFiles::mp4(360), ['name' => 'long.mp4', 'type' => 'video/mp4', 'purpose' => 'media']);

    $this->postJson('/media', ['upload' => $id])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file' => 'A video may be at most 5 minutes long. This one is 6:00.']);

    expect(Media::count())->toBe(0)->and(Upload::whereKey($id)->exists())->toBeTrue();

    $short = Tus::upload($this, VideoFiles::mp4(12), ['name' => 'short.mp4', 'type' => 'video/mp4', 'purpose' => 'media']);
    $this->postJson('/media', ['upload' => $short])->assertOk();

    expect(Media::sole()->duration_seconds)->toBe(12);
});

test("the organization's 512 MB counts the uploads still open: the one that would pass it is refused before a byte", function () {
    Media::factory()->create(['organization_id' => $this->organization->id, 'size' => 500 * 1024 * 1024, 'thumbnail_path' => null]);

    Tus::open($this, 10 * 1024 * 1024, ['name' => 'a.jpg', 'purpose' => 'media'])->assertCreated();

    Tus::open($this, 5 * 1024 * 1024, ['name' => 'b.jpg', 'purpose' => 'media'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file' => 'Not enough storage: this needs 5 MB, and Alpha Mart has 2 MB left of its 512 MB. Delete files you no longer use to make room.']);

    Tus::open($this, 1024 * 1024, ['name' => 'c.jpg', 'purpose' => 'media'])->assertCreated();

    expect(Upload::count())->toBe(2)
        ->and(app(OrganizationStorage::class)->used($this->organization->id))->toBe(500 * 1024 * 1024);
});

test('nothing is opened that the door would refuse, and each refusal says why', function () {
    Tus::open($this, 100, ['name' => 'virus.exe', 'purpose' => 'media'])
        ->assertStatus(415)->assertJsonPath('message', 'Only images (JPG, PNG, GIF, WEBP) and videos (MP4, WEBM) can be uploaded.');
    Tus::open($this, 256000 * 1024 + 1, ['name' => 'huge.mp4', 'purpose' => 'media'])
        ->assertStatus(413)->assertJsonPath('message', 'The file may not be larger than 250 MB.');
    Tus::open($this, 100, ['name' => 'a.jpg'])->assertStatus(422)->assertJsonValidationErrors(['file' => 'Say where the file is going.']);
    Tus::open($this, 0, ['name' => 'a.jpg', 'purpose' => 'media'])->assertStatus(422)->assertJsonValidationErrors(['file' => 'The file is empty.']);

    // The permission the door asks, asked here: a campaign is the platform's.
    Tus::open($this, 100, ['name' => 'a.jpg', 'purpose' => 'campaign'])->assertForbidden();

    // A channel out of reach is not found, like its door says.
    $theirs = Channel::factory()->create(['organization_id' => Organization::factory()->create()->id]);
    Tus::open($this, 100, ['name' => 'a.jpg', 'purpose' => 'channel', 'channel' => $theirs->id])->assertNotFound();

    // An organization's person with no organization chosen (one of two: a lone one is chosen for them) has nowhere to put it.
    inASecondOrganization(auth()->user());
    $this->withSession(['current_organization_id' => null]);
    Tus::open($this, 100, ['name' => 'a.jpg', 'purpose' => 'media'])->assertForbidden();

    expect(Upload::count())->toBe(0);
});

test('a chunk goes only where the upload is, and never past its end', function () {
    $id = Tus::idOf(Tus::open($this, 10, ['name' => 'a.jpg', 'purpose' => 'media']));

    Tus::send($this, $id, 0, '12345')->assertNoContent()->assertHeader('Upload-Offset', '5');
    Tus::send($this, $id, 0, '12345')->assertStatus(409);
    Tus::send($this, $id, 5, '1234567')->assertStatus(413);
    Tus::send($this, $id, 5, '12345', ['Content-Type' => 'application/json'])->assertStatus(415);

    Tus::ask($this, $id)->assertHeader('Upload-Offset', '5');
    expect(Storage::disk('uploads')->get("{$id}.part"))->toBe('12345');
});

test('an upload given up goes, bytes and all', function () {
    $id = Tus::idOf(Tus::open($this, 10, ['name' => 'a.jpg', 'purpose' => 'media']));
    Tus::send($this, $id, 0, '12345')->assertNoContent();

    Tus::giveUp($this, $id)->assertNoContent();

    Tus::ask($this, $id)->assertNotFound();
    expect(Upload::count())->toBe(0)->and(Storage::disk('uploads')->exists("{$id}.part"))->toBeFalse();
});

test('the protocol answers as tus 1.0 does', function () {
    $this->call('OPTIONS', '/uploads', [], [], [], ['HTTP_ACCEPT' => 'application/json'])
        ->assertNoContent()
        ->assertHeader('Tus-Version', '1.0.0')
        ->assertHeader('Tus-Extension', 'creation,termination')
        ->assertHeader('Tus-Max-Size', (string) (256000 * 1024));

    Tus::open($this, 10, ['name' => 'a.jpg', 'purpose' => 'media'], ['Tus-Resumable' => '0.2.2'])->assertStatus(412);
    Tus::open($this, 10, ['name' => 'a.jpg', 'purpose' => 'media'], ['Upload-Defer-Length' => '1'])->assertStatus(400);
    Tus::open($this, 10, ['name' => 'a.jpg', 'purpose' => 'media'], ['Upload-Length' => 'ten'])->assertStatus(400);

    $opened = Tus::open($this, 10, ['name' => 'a.jpg', 'purpose' => 'media']);
    $opened->assertCreated()->assertHeader('Tus-Resumable', '1.0.0')->assertHeader('Upload-Offset', '0');
    expect($opened->headers->get('Location'))->toStartWith(url('/uploads/'));
});

test('an upload never finished goes after a day, and so does a part no upload names — nothing else', function () {
    $id = Tus::idOf(Tus::open($this, 10, ['name' => 'a.jpg', 'purpose' => 'media']));
    $disk = Storage::disk('uploads');
    $disk->put('stray.part', 'left behind');
    touch($disk->path('stray.part'), now()->subHours(2)->getTimestamp());
    $disk->put('notes.txt', 'not a part');
    touch($disk->path('notes.txt'), now()->subHours(2)->getTimestamp());

    $this->artisan('uploads:prune')->expectsOutputToContain('1 upload(s) removed.')->assertSuccessful();
    expect(Upload::count())->toBe(1);

    $this->travel(25)->hours();
    $this->artisan('uploads:prune')->expectsOutputToContain('1 upload(s) removed.')->assertSuccessful();

    expect(Upload::count())->toBe(0)->and($disk->allFiles())->toBe(['notes.txt']);
    Tus::ask($this, $id)->assertNotFound();
});

test('a deleted organization takes the uploads still on their way into it', function () {
    $id = Tus::idOf(Tus::open($this, 10, ['name' => 'a.jpg', 'purpose' => 'media']));

    $this->organization->delete();

    expect(Upload::count())->toBe(0)->and(Storage::disk('uploads')->exists("{$id}.part"))->toBeFalse();
});

test("an upload counts to the server's reserve with every open upload's missing bytes", function () {
    config(['signage.upload_reserve_bytes' => 1024 * 1024 * 1024]);
    app()->instance(DiskGuard::class, new DiskGuard(fn () => 1024 * 1024 * 1024 + 30 * 1024 * 1024));

    Tus::open($this, 20 * 1024 * 1024, ['name' => 'a.mp4', 'purpose' => 'media'])->assertCreated();
    Tus::open($this, 20 * 1024 * 1024, ['name' => 'b.mp4', 'purpose' => 'media'])
        ->assertStatus(422)->assertJsonValidationErrors('file');
});

test('it is tidied every hour', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event) => str_contains((string) $event->command, 'uploads:prune'));

    expect($event)->not->toBeNull()->and($event->expression)->toBe('0 * * * *');
});
