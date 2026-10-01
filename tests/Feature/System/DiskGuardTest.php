<?php

use App\Models\BuilderAd;
use App\Models\Channel;
use App\Models\Media;
use App\Models\Organization;
use App\Notifications\DiskAlmostFullNotification;
use App\Notifications\DiskSpaceLowNotification;
use App\Services\DiskGuard;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| The server's own disk keeps its reserve
|--------------------------------------------------------------------------
|
| Owner's decision, 2026-09-29: every organization has its 512 MB, but anybody with an inbox can make an organization, and the
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

    $this->organization = Organization::factory()->create(['name' => 'Alpha Mart']);
    $this->manager = createOrganizationUser($this->organization, ['media-store', 'channel-update', 'ad-store', 'ad-update'], 'Manager');
    $this->actingAs($this->manager)->withSession(['current_organization_id' => $this->organization->id]);
});

test('an upload that would eat into the reserve is refused at every door, and nothing of it is kept', function () {
    Notification::fake();
    $this->free = 5 * GB + 512 * 1024;   // half a megabyte above the reserve

    $channel = Channel::factory()->create(['organization_id' => $this->organization->id]);
    $ad = BuilderAd::factory()->withText()->create(['organization_id' => $this->organization->id]);

    $this->postJson('/media', ['file' => UploadedFile::fake()->create('menu.jpg', 1024)])
        ->assertStatus(422)->assertJsonValidationErrors(['file' => DISK_FULL]);
    $this->postJson("/channels/{$channel->id}/ads", ['file' => UploadedFile::fake()->create('deal.jpg', 1024), 'seconds' => 10])
        ->assertStatus(422)->assertJsonValidationErrors(['file' => DISK_FULL]);
    $this->postJson('/builder/assets', ['file' => UploadedFile::fake()->create('logo.jpg', 1024)])
        ->assertStatus(422)->assertJsonValidationErrors(['file' => DISK_FULL]);

    // A published page is a few kilobytes: with less than that above the reserve, it waits too.
    $this->free = 5 * GB + 100;
    $this->postJson("/builder/{$ad->id}/publish")->assertStatus(422)->assertJsonValidationErrors(['publish' => DISK_FULL]);

    // Above the organizations: the platform's own library and the ads network have no wall of their own, but the disk does.
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
    // The organization's own people are not the ones who can make room on the server.
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

test('below the warning the super admins are told once a day, and uploads still work', function () {
    // Owner's rule, 2026-09-29: "server per jab 10gb khaali rahe toh email aye".
    Notification::fake();
    config(['signage.disk_warning_bytes' => 10 * GB]);
    $admin = createSuperAdmin();
    $this->free = 9 * GB;

    $this->artisan('disk:check')
        ->expectsOutputToContain('Free: 9 GB. The super admins are warned below 10 GB, and uploads stop below 5 GB.')
        ->expectsOutputToContain('the super admins have been emailed')
        ->assertSuccessful();
    $this->artisan('disk:check')->doesntExpectOutputToContain('emailed')->assertSuccessful();

    Notification::assertSentToTimes($admin, DiskSpaceLowNotification::class, 1);
    Notification::assertNotSentTo($this->manager, DiskSpaceLowNotification::class);

    // Uploads still work: 9 GB free is well above the 5 GB reserve.
    $this->postJson('/media', ['file' => UploadedFile::fake()->create('menu.jpg', 1024)])->assertOk();

    // A day later, still below: told again.
    $this->travel(DiskGuard::WARNING_HOURS)->hours();
    $this->travel(1)->minutes();
    $this->artisan('disk:check')->assertSuccessful();

    Notification::assertSentToTimes($admin, DiskSpaceLowNotification::class, 2);
});

test('above the warning nobody is told, and a drop after the disk had room again is told at once', function () {
    Notification::fake();
    config(['signage.disk_warning_bytes' => 10 * GB]);
    $admin = createSuperAdmin();

    $this->free = 11 * GB;
    $this->artisan('disk:check')->assertSuccessful();
    Notification::assertNothingSent();

    $this->free = 9 * GB;
    $this->artisan('disk:check');
    $this->free = 12 * GB;                  // files deleted: room again
    $this->artisan('disk:check');
    $this->free = (int) (9.5 * GB);         // and below again, the same day
    $this->artisan('disk:check');

    Notification::assertSentToTimes($admin, DiskSpaceLowNotification::class, 2);
});

test('the disk is looked at every hour, whether anybody uploads or not', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event) => str_contains((string) $event->command, 'disk:check'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
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
