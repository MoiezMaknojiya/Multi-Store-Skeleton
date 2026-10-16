<?php

use App\Models\Media;
use App\Models\Organization;
use App\Models\PlaylistItem;
use App\Models\Screen;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Two saves of one playlist at the same moment
|--------------------------------------------------------------------------
|
| A save replaces the whole list, so it is measured against what the list is (the version) — and that is read
| under the screen's own row lock, the transaction's first read (the review of 2026-10-15). Read before the
| transaction, as it was, two saves sent together both passed it and the second wiped the first without a word.
|
| One PHP process cannot send two requests at once, so the other save is made to land exactly where it would
| hurt: the moment the save's transaction opens, before its first statement runs. Under the lock that is a turn
| taken before ours; checked before the transaction it was a write in between the check and the lines.
|
*/

beforeEach(function () {
    $this->organization = Organization::factory()->create();
    $this->actor = createOrganizationUser($this->organization, ['screen-view', 'screen-playlist']);
    $this->screen = Screen::factory()->create(['organization_id' => $this->organization->id]);
    $this->poster = Media::factory()->create(['organization_id' => $this->organization->id, 'title' => 'Poster']);
    $this->colleagues = Media::factory()->create(['organization_id' => $this->organization->id, 'title' => 'Colleague’s poster']);

    $this->actingAs($this->actor)->withSession(['current_organization_id' => $this->organization->id]);
});

/** Runs $meanwhile once, the moment the request's own transaction has opened and before its first statement. */
function meanwhileInTheSave(Closure $meanwhile): void
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

test('a save sent at the same moment as another is measured against what the other wrote: a 409, nothing lost', function () {
    $version = $this->screen->playlistFingerprint();

    // A colleague's save lands first: a poster of theirs.
    meanwhileInTheSave(fn () => PlaylistItem::create([
        'screen_id' => $this->screen->id, 'media_id' => $this->colleagues->id, 'position' => 0, 'duration_seconds' => 10,
    ]));

    $this->putJson("/screens/{$this->screen->id}/playlist", [
        'items' => [['media_id' => $this->poster->id, 'duration_seconds' => 10, 'rules' => []]],
        'version' => $version,
    ])->assertStatus(409)->assertJson([
        'message' => 'Someone else changed this playlist while you were editing. Reload the page to see their changes, then make yours again.',
    ]);

    expect($this->screen->playlistItems()->pluck('media_id')->all())->toBe([$this->colleagues->id]);
});

test('a screen deleted while its save was on its way answers 404, not the foreign key’s 500', function () {
    $version = $this->screen->playlistFingerprint();

    meanwhileInTheSave(fn () => DB::table('screens')->where('id', $this->screen->id)->delete());

    $this->putJson("/screens/{$this->screen->id}/playlist", [
        'items' => [['media_id' => $this->poster->id, 'duration_seconds' => 10, 'rules' => []]],
        'version' => $version,
    ])->assertNotFound();

    expect(PlaylistItem::count())->toBe(0);
});

test('a copy onto a screen deleted meanwhile answers 404, and one still there is copied to', function () {
    PlaylistItem::create(['screen_id' => $this->screen->id, 'media_id' => $this->poster->id, 'position' => 0, 'duration_seconds' => 10]);
    $gone = Screen::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Gone TV']);
    $kept = Screen::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Window TV']);

    meanwhileInTheSave(fn () => DB::table('screens')->where('id', $gone->id)->delete());
    $this->postJson("/screens/{$this->screen->id}/playlist/copy", ['target_screen_ids' => [$gone->id]])->assertNotFound();

    meanwhileInTheSave(fn () => DB::table('screens')->where('id', $gone->id)->delete());
    $this->postJson("/screens/{$this->screen->id}/playlist/copy", ['target_screen_ids' => [$kept->id]])
        ->assertOk()->assertJson(['screens' => ['Window TV']]);

    expect($kept->playlistItems()->pluck('media_id')->all())->toBe([$this->poster->id]);
});

test('a copy waits for a save of its source that landed first, and copies what that save wrote', function () {
    PlaylistItem::create(['screen_id' => $this->screen->id, 'media_id' => $this->poster->id, 'position' => 0, 'duration_seconds' => 10]);
    $target = Screen::factory()->create(['organization_id' => $this->organization->id]);

    // The source's own save lands as the copy begins: the copy reads it under the source's lock.
    meanwhileInTheSave(function () {
        $this->screen->playlistItems()->delete();
        PlaylistItem::create(['screen_id' => $this->screen->id, 'media_id' => $this->colleagues->id, 'position' => 0, 'duration_seconds' => 20]);
    });

    $this->postJson("/screens/{$this->screen->id}/playlist/copy", ['target_screen_ids' => [$target->id]])->assertOk();

    expect($target->playlistItems()->get(['media_id', 'duration_seconds'])->toArray())
        ->toBe([['media_id' => $this->colleagues->id, 'duration_seconds' => 20]]);
});

test('a save on a version that is current still goes through, and returns the next one', function () {
    $saved = $this->putJson("/screens/{$this->screen->id}/playlist", [
        'items' => [['media_id' => $this->poster->id, 'duration_seconds' => 10, 'rules' => []]],
        'version' => $this->screen->playlistFingerprint(),
    ])->assertOk()->json();

    expect($saved['version'])->toBe($this->screen->fresh()->playlistFingerprint());

    $this->putJson("/screens/{$this->screen->id}/playlist", [
        'items' => [['media_id' => $this->poster->id, 'duration_seconds' => 20, 'rules' => []]],
        'version' => $saved['version'],
    ])->assertOk();
});
