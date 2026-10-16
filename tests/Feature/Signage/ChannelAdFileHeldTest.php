<?php

use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Media;
use App\Models\Organization;
use App\Models\Upload;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Tus;

/*
|--------------------------------------------------------------------------
| The file a channel's ad shows, held until the ad is written
|--------------------------------------------------------------------------
|
| An ad holds a library row by id (docs/CHANNEL-CONTENT-SPEC.md). The form names the file, the rules find it, and then
| the ad is written under the file's row lock — the lock a playlist's save takes too. A file deleted between the two
| was read under that lock as nothing at all and written anyway: the foreign key's 500 on MySQL (the review of
| 2026-10-15). And a file sent in chunks joins the library it was dropped for, never another channel's.
|
| One PHP process cannot send two requests at once, so the delete is made to land exactly where it would hurt: the
| moment the ad's own transaction opens, before its first statement runs.
|
*/

beforeEach(function () {
    Storage::fake('public');
    Storage::fake('local');
    Storage::fake('uploads');

    $this->organization = Organization::factory()->create(['name' => 'Alpha Mart']);
    $this->manager = createOrganizationUser($this->organization, ['channel-view', 'channel-update'], 'Manager');
    $this->actingAs($this->manager)->withSession(['current_organization_id' => $this->organization->id]);

    $this->channel = Channel::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Deals']);
    $this->poster = Media::factory()->create(['organization_id' => $this->organization->id, 'title' => 'Winter sale', 'type' => Media::TYPE_IMAGE]);
});

/** Runs $meanwhile once, the moment the request's own transaction has opened and before its first statement. */
function meanwhileInTheAdsWrite(Closure $meanwhile): void
{
    $outside = DB::transactionLevel();
    $done = false;

    DB::beforeExecuting(function (string $query, array $bindings, Connection $connection) use ($outside, &$done, $meanwhile): void {
        if ($done || $connection->transactionLevel() <= $outside) {
            return;
        }

        $done = true;
        $meanwhile();
    });
}

/** A small real JPEG. */
function channelAdJpeg(): string
{
    $image = imagecreatetruecolor(64, 64);

    ob_start();
    imagejpeg($image);

    return (string) ob_get_clean();
}

test('a file deleted while its ad was on its way is refused in the form’s words, never written', function () {
    meanwhileInTheAdsWrite(fn () => DB::table('media')->where('id', $this->poster->id)->delete());

    $this->postJson("/channels/{$this->channel->id}/ads", ['media_id' => $this->poster->id, 'seconds' => 12])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['media_id' => 'Winter sale was deleted meanwhile. Choose another file.']);

    expect(ChannelAd::count())->toBe(0);
});

test('an ad changed to a file deleted meanwhile keeps the file it showed', function () {
    $menu = Media::factory()->create(['organization_id' => $this->organization->id, 'title' => 'Menu', 'type' => Media::TYPE_IMAGE]);
    $ad = ChannelAd::create(['channel_id' => $this->channel->id, 'media_id' => $menu->id, 'title' => 'Menu', 'duration_seconds' => 10, 'position' => 1]);

    meanwhileInTheAdsWrite(fn () => DB::table('media')->where('id', $this->poster->id)->delete());

    $this->postJson("/channels/{$this->channel->id}/ads/{$ad->id}", ['media_id' => $this->poster->id, 'seconds' => 12])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['media_id' => 'Winter sale was deleted meanwhile. Choose another file.']);

    expect($ad->fresh()->media_id)->toBe($menu->id);
});

test('a file still there is added as before', function () {
    $this->postJson("/channels/{$this->channel->id}/ads", ['media_id' => $this->poster->id, 'seconds' => 12])->assertOk();

    expect(ChannelAd::sole()->media_id)->toBe($this->poster->id);
});

test('above the organizations, a file sent in chunks joins only the library of the channel it was dropped for', function () {
    $this->actingAs(createSuperAdmin());
    session()->forget('current_organization_id');
    $platforms = Channel::factory()->create(['organization_id' => null, 'name' => 'Platform deals']);

    // Dropped on Alpha Mart's channel, posted to the platform's: refused, and nothing joins the platform's library. Nothing
    // will add that upload where it was opened for, so it is given up (TakesAFinishedUpload::assertUploadOpenedFor).
    $id = Tus::upload($this, channelAdJpeg(), ['name' => 'deal.jpg', 'purpose' => 'channel', 'channel' => (string) $this->channel->id]);
    $this->postJson("/channels/{$platforms->id}/ads", ['upload' => $id, 'seconds' => 12])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file' => 'This file was uploaded for another library. Choose it again to add it here.']);

    expect(Media::whereNull('organization_id')->count())->toBe(0)
        ->and(ChannelAd::count())->toBe(0)
        ->and(Upload::whereKey($id)->exists())->toBeFalse();

    // Posted where it was dropped, it goes in — and so does one dropped on the platform's channel, there.
    $id = Tus::upload($this, channelAdJpeg(), ['name' => 'deal.jpg', 'purpose' => 'channel', 'channel' => (string) $this->channel->id]);
    $this->postJson("/channels/{$this->channel->id}/ads", ['upload' => $id, 'seconds' => 12])->assertOk();
    $id = Tus::upload($this, channelAdJpeg(), ['name' => 'cola.jpg', 'purpose' => 'channel', 'channel' => (string) $platforms->id]);
    $this->postJson("/channels/{$platforms->id}/ads", ['upload' => $id, 'seconds' => 12])->assertOk();

    expect(ChannelAd::where('channel_id', $this->channel->id)->sole()->media->organization_id)->toBe($this->organization->id)
        ->and(ChannelAd::where('channel_id', $platforms->id)->sole()->media->organization_id)->toBeNull();
});
