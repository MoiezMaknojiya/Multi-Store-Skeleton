<?php

use App\Models\BuilderAd;
use App\Models\Channel;
use App\Models\Media;
use App\Models\Store;
use App\Notifications\DiskAlmostFullNotification;
use App\Services\DiskGuard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| The server's own disk keeps its reserve
|--------------------------------------------------------------------------
|
| Owner's decision, 2026-09-29: every shop has its 512 MB, but anybody with an inbox can make a shop, and the
| platform's library and the ads network have no wall of their own — so an upload that would leave the server's
| disk with less than its reserve (5 GB) is refused at every door, whoever uploads, and the super admins are told
| by email at most once every six hours. The disk is measured through a closure here, so nothing is filled.
|
*/

const GB = 1024 ** 3;

const DISK_FULL = 'The server is almost out of space, so uploads are paused for now. Please try again later: the team has been told.';

beforeEach(function () {
    Storage::fake('public');
    config(['signage.upload_reserve_bytes' => 5 * GB]);

    $this->free = 50 * GB;
    app()->instance(DiskGuard::class, new DiskGuard(fn () => $this->free));

    $this->store = Store::factory()->create(['name' => 'Alpha Mart']);
    $this->manager = createStoreUser($this->store, ['media-store', 'channel-update', 'ad-store', 'ad-update'], 'Manager');
    $this->actingAs($this->manager)->withSession(['current_store_id' => $this->store->id]);
});

test('an upload that would eat into the reserve is refused at every door, and nothing of it is kept', function () {
    Notification::fake();
    $this->free = 5 * GB + 512 * 1024;   // half a megabyte above the reserve

    $channel = Channel::factory()->create(['store_id' => $this->store->id]);
    $ad = BuilderAd::factory()->withText()->create(['store_id' => $this->store->id]);

    $this->postJson('/media', ['file' => UploadedFile::fake()->create('menu.jpg', 1024)])
        ->assertStatus(422)->assertJsonValidationErrors(['file' => DISK_FULL]);
    $this->postJson("/channels/{$channel->id}/ads", ['file' => UploadedFile::fake()->create('deal.jpg', 1024), 'seconds' => 10])
        ->assertStatus(422)->assertJsonValidationErrors(['file' => DISK_FULL]);
    $this->postJson('/builder/assets', ['file' => UploadedFile::fake()->create('logo.jpg', 1024)])
        ->assertStatus(422)->assertJsonValidationErrors(['file' => DISK_FULL]);

    // A published page is a few kilobytes: with less than that above the reserve, it waits too.
    $this->free = 5 * GB + 100;
    $this->postJson("/builder/{$ad->id}/publish")->assertStatus(422)->assertJsonValidationErrors(['publish' => DISK_FULL]);

    // Above the stores: the platform's own library and the ads network have no wall of their own, but the disk does.
    $this->actingAs(createSuperAdmin())->withSession([]);
    $this->postJson('/media', ['file' => UploadedFile::fake()->create('platform.jpg', 1024)])
        ->assertStatus(422)->assertJsonValidationErrors(['file' => DISK_FULL]);
    $this->postJson('/campaigns', campaignBody(['file' => UploadedFile::fake()->create('coke.jpg', 1024)]))
        ->assertStatus(422)->assertJsonValidationErrors(['file' => DISK_FULL]);

    expect(Media::count())->toBe(0)
        ->and($ad->fresh()->media_id)->toBeNull()
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

test('an upload that leaves exactly the reserve goes through', function () {
    $this->free = 5 * GB + 1024 * 1024;

    $this->postJson('/media', ['file' => UploadedFile::fake()->create('menu.jpg', 1024)])->assertOk();
});

test('the super admins are told once, and again only after six hours', function () {
    Notification::fake();
    $first = createSuperAdmin();
    $second = createSuperAdmin();
    $this->free = 4 * GB;

    $this->postJson('/media', ['file' => UploadedFile::fake()->create('a.jpg', 10)])->assertStatus(422);
    $this->postJson('/media', ['file' => UploadedFile::fake()->create('b.jpg', 10)])->assertStatus(422);

    Notification::assertSentToTimes($first, DiskAlmostFullNotification::class, 1);
    Notification::assertSentToTimes($second, DiskAlmostFullNotification::class, 1);
    // The shop's own people are not the ones who can make room on the server.
    Notification::assertNotSentTo($this->manager, DiskAlmostFullNotification::class);

    $this->travel(DiskGuard::ALERT_HOURS)->hours();
    $this->travel(1)->minutes();
    $this->postJson('/media', ['file' => UploadedFile::fake()->create('c.jpg', 10)])->assertStatus(422);

    Notification::assertSentToTimes($first, DiskAlmostFullNotification::class, 2);
});

test('a mail server that refuses the warning changes nothing: the upload is refused in the same words', function () {
    createSuperAdmin();
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1, 'mail.mailers.smtp.timeout' => 1]);
    $this->free = 4 * GB;

    $this->postJson('/media', ['file' => UploadedFile::fake()->create('a.jpg', 10)])
        ->assertStatus(422)->assertJsonValidationErrors(['file' => DISK_FULL]);
});

test('a system that cannot say how much is free refuses nothing', function () {
    $this->free = false;   // what disk_free_space answers when it cannot tell

    $this->postJson('/media', ['file' => UploadedFile::fake()->create('menu.jpg', 1024)])->assertOk();
});

test('the reserve is read from the config, and sizes are said in gigabytes', function () {
    expect((new DiskGuard(fn () => 0))->reserve())->toBe(5 * GB)
        ->and(DiskGuard::inWords(5 * GB))->toBe('5 GB')
        ->and(DiskGuard::inWords((int) (4.25 * GB)))->toBe('4.3 GB')
        ->and(DiskGuard::inWords(700 * 1024 * 1024))->toBe('700 MB');
});

/** A campaign, as its form sends one. */
function campaignBody(array $overrides = []): array
{
    return array_replace([
        'name' => 'Coca-Cola Ramadan', 'advertiser_name' => 'Coca-Cola', 'duration_seconds' => 15,
        'is_active' => true, 'screen_ids' => [],
    ], $overrides);
}
