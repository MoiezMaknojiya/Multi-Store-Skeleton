<?php

use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Media;
use App\Models\Organization;
use App\Models\PlaylistItem;
use App\Models\Screen;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TransactionStatements;

/*
|--------------------------------------------------------------------------
| Every write of a screen's lines takes its locks in one order, before it reads
|--------------------------------------------------------------------------
|
| The files the lines name, then their channels, then the screens — and only then a plain read (the second review of
| 2026-10-15). On MySQL's REPEATABLE READ every plain read of a transaction is answered from the snapshot its FIRST
| plain read takes: a save that read the screen's version before the files' locks were granted then asked "is this
| file in a channel?" of a snapshot from before a channel's Add Ad committed, and the file landed on the playlist and
| in the channel both — playing twice. And a file's delete takes the file, then (through the foreign key) the screens
| it is the holding picture of: a save that took its screen before its files waited on the delete in a circle.
|
| SQLite has neither snapshots nor row locks, so two requests cannot be raced here; these tests read which statements
| each transaction runs and which of them lock (TransactionStatements), which is what MySQL decides by.
|
| The third review of 2026-10-15 found two more writes that took a screen before its files: a screen given a holding
| picture (its foreign key is checked with a shared lock on the file, asked for once the UPDATE holds the screen) and an
| organization's delete (its screens went before its channels and files). Both now take the files first.
|
*/

beforeEach(function () {
    // Every file these tests delete is a factory's row, and its path is only a name: never the real disk's.
    Storage::fake('public');

    $this->organization = Organization::factory()->create(['name' => 'Alpha Mart']);
    $this->actor = createOrganizationUser($this->organization, [
        'screen-view', 'screen-update', 'screen-playlist', 'channel-view', 'channel-update', 'media-view', 'media-destroy',
    ]);
    $this->actingAs($this->actor)->withSession(['current_organization_id' => $this->organization->id]);

    $this->screen = Screen::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Counter TV']);
    $this->poster = Media::factory()->create(['organization_id' => $this->organization->id, 'title' => 'Poster']);
    $this->channel = Channel::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Our Deals']);
    ChannelAd::factory()->create([
        'channel_id' => $this->channel->id,
        'media_id' => Media::factory()->create(['organization_id' => $this->organization->id, 'title' => 'Deal'])->id,
    ]);
});

/** Where the first statement matching $pattern is among $statements, or null when none does. */
function statementAt(array $statements, string $pattern): ?int
{
    $found = preg_grep($pattern, $statements);

    return $found === [] ? null : array_key_first($found);
}

/** The statements of a write that locks: every locking read first, in the order given, and no plain read before them. */
function expectLocksFirst(array $statements, array $locks): void
{
    expect(count($statements))->toBeGreaterThan(count($locks));

    foreach ($locks as $index => $prefix) {
        expect($statements[$index])->toStartWith($prefix)
            ->and(TransactionStatements::locksForUpdate($statements[$index]))->toBeTrue();
    }

    foreach (array_slice($statements, count($locks)) as $statement) {
        expect(TransactionStatements::locksForUpdate($statement) || TransactionStatements::locksForShare($statement))
            ->toBeFalse("A lock after a plain read: {$statement}");
    }
}

test('a save holds its files, then its channels, then its screen, before it reads the version or the channels', function () {
    $version = $this->screen->playlistFingerprint();
    $log = TransactionStatements::record();

    $this->putJson("/screens/{$this->screen->id}/playlist", ['version' => $version, 'items' => [
        ['media_id' => $this->poster->id, 'duration_seconds' => 10, 'rules' => []],
        ['channel_id' => $this->channel->id, 'rules' => []],
    ]])->assertOk();

    $statements = $log->of('/^insert into "playlist_items"/');

    expectLocksFirst($statements, [
        'select "id" from "media" where "id" in (',
        'select "id" from "channels" where "id" in (',
        'select * from "screens" where "id" in (',
    ]);

    // The version and the channels' files are read after every lock: from a snapshot that sees what committed before.
    $plainReads = array_slice($statements, 3);
    expect(preg_grep('/from "playlist_items" where "playlist_items"\."screen_id" = \?/', $plainReads))->not->toBeEmpty()
        ->and(preg_grep('/from "channel_ads"/', $plainReads))->not->toBeEmpty();
});

test('a copy holds the files and channels of the source, then the source and its targets, before it reads anything', function () {
    PlaylistItem::create(['screen_id' => $this->screen->id, 'media_id' => $this->poster->id, 'position' => 0, 'duration_seconds' => 10]);
    PlaylistItem::create(['screen_id' => $this->screen->id, 'channel_id' => $this->channel->id, 'position' => 1]);
    $window = Screen::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Window TV']);
    $log = TransactionStatements::record();

    $this->postJson("/screens/{$this->screen->id}/playlist/copy", ['target_screen_ids' => [$window->id]])->assertOk();

    expectLocksFirst($log->of('/^insert into "playlist_items"/'), [
        'select "id" from "media" where "id" in (',
        'select "id" from "channels" where "id" in (',
        'select * from "screens" where "id" in (',
    ]);

    expect($window->playlistItems()->orderBy('position')->get(['media_id', 'channel_id'])->toArray())->toBe([
        ['media_id' => $this->poster->id, 'channel_id' => null],
        ['media_id' => null, 'channel_id' => $this->channel->id],
    ]);
});

test("a file's delete holds the screens it is the holding picture of after the file and before the row goes", function () {
    $this->screen->update(['default_media_id' => $this->poster->id]);
    PlaylistItem::create(['screen_id' => $this->screen->id, 'media_id' => $this->poster->id, 'position' => 0, 'duration_seconds' => 10]);
    $log = TransactionStatements::record();

    $this->deleteJson("/media/{$this->poster->id}")->assertOk();

    $statements = $log->of('/^delete from "media"/');
    $screens = statementAt($statements, '/^select "id" from "screens" where "default_media_id" = \? order by "id" asc/');
    $delete = statementAt($statements, '/^delete from "media"/');

    // The file first — as a save and a channel's Add Ad take it — then its screens, then the delete that empties them.
    expect($statements[0])->toStartWith('select "id" from "media" where "media"."id" = ?')
        ->and(TransactionStatements::locksForUpdate($statements[0]))->toBeTrue()
        ->and($screens)->not->toBeNull()
        ->and(TransactionStatements::locksForUpdate($statements[$screens]))->toBeTrue()
        ->and($screens)->toBeLessThan($delete);

    expect($this->screen->fresh()->default_media_id)->toBeNull()
        ->and($this->screen->playlistItems()->count())->toBe(0);
});

test("a channel's Add Ad holds the file before it reads whether a playlist holds it", function () {
    $log = TransactionStatements::record();

    $this->postJson("/channels/{$this->channel->id}/ads", ['media_id' => $this->poster->id, 'seconds' => 10])->assertOk();

    $statements = $log->of('/^insert into "channel_ads"/');
    $playlists = statementAt($statements, '/from "playlist_items" where "media_id" = \?/');

    expect($statements[0])->toStartWith('select "id" from "media" where "media"."id" = ?')
        ->and(TransactionStatements::locksForUpdate($statements[0]))->toBeTrue()
        ->and($playlists)->toBeGreaterThan(0);
});

test('a copy whose source is saved again at every try says so, and copies nothing', function () {
    PlaylistItem::create(['screen_id' => $this->screen->id, 'media_id' => $this->poster->id, 'position' => 0, 'duration_seconds' => 10]);
    $window = Screen::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Window TV']);
    $outside = DB::transactionLevel();
    $fresh = false;

    // At the start of every try, before its first statement, a colleague saves the source with a file of their own.
    Event::listen(TransactionBeginning::class, function () use (&$fresh, $outside) {
        $fresh = DB::transactionLevel() === $outside + 1;
    });
    DB::beforeExecuting(function () use (&$fresh) {
        if ($fresh) {
            $fresh = false;
            $this->screen->playlistItems()->delete();
            PlaylistItem::create([
                'screen_id' => $this->screen->id,
                'media_id' => Media::factory()->create(['organization_id' => $this->organization->id])->id,
                'position' => 0,
                'duration_seconds' => 10,
            ]);
        }
    });

    $this->postJson("/screens/{$this->screen->id}/playlist/copy", ['target_screen_ids' => [$window->id]])
        ->assertStatus(409)
        ->assertJson(['message' => 'This playlist kept changing while it was being copied. Reload the page and copy it again.']);

    expect($window->playlistItems()->count())->toBe(0);
});

test('a screen given a holding picture holds the one it had and the one it gets, then the screen, before it writes', function () {
    $before = Media::factory()->create(['organization_id' => $this->organization->id, 'title' => 'Old Poster']);
    $this->screen->update(['default_media_id' => $before->id]);
    $log = TransactionStatements::record();

    $this->putJson("/screens/{$this->screen->id}", [
        'name' => 'Counter TV', 'orientation' => 'landscape', 'default_media_id' => $this->poster->id,
    ])->assertOk();

    $statements = $log->of('/^update "screens"/');

    // Shared, as the foreign key reads the file: a save holding it waits for the screen, never the screen for it.
    expect($statements[0])->toStartWith('select "id" from "media" where "id" in (?, ?) order by "id" asc')
        ->and(TransactionStatements::locksForShare($statements[0]))->toBeTrue()
        ->and($statements[1])->toStartWith('select "id" from "screens" where "screens"."id" = ?')
        ->and(TransactionStatements::locksForUpdate($statements[1]))->toBeTrue()
        ->and($statements[2])->toStartWith('update "screens" set');

    expect($this->screen->fresh()->default_media_id)->toBe($this->poster->id);
});

test('a screen renamed holds no file: only a new holding picture is read by the foreign key', function () {
    $this->screen->update(['default_media_id' => $this->poster->id]);
    $log = TransactionStatements::record();

    $this->putJson("/screens/{$this->screen->id}", [
        'name' => 'Front TV', 'orientation' => 'landscape', 'default_media_id' => $this->poster->id,
    ])->assertOk();

    $statements = $log->of('/^update "screens"/');

    expect($statements[0])->toStartWith('select "id" from "screens" where "screens"."id" = ?')
        ->and(TransactionStatements::locksForUpdate($statements[0]))->toBeTrue()
        ->and(preg_grep('/from "media"/', $statements))->toBeEmpty();
    expect($this->screen->fresh())->name->toBe('Front TV')->default_media_id->toBe($this->poster->id);
});

/** Runs $meanwhile once, right after the screen form's organization wall let its holding picture through. */
function rightAfterTheHoldingPictureCheck(Closure $meanwhile): void
{
    $done = false;

    DB::listen(function (QueryExecuted $query) use (&$done, $meanwhile) {
        if (! $done && str_starts_with($query->sql, 'select exists(select * from "media" where "id" = ? and "organization_id" = ?)')) {
            $done = true;
            $meanwhile();
        }
    });
}

test('a holding picture deleted between its check and the write is refused under its field, never the foreign key', function () {
    // Deleted in another tab, the moment after the organization wall let it through.
    rightAfterTheHoldingPictureCheck(fn () => Media::whereKey($this->poster->id)->delete());

    $this->putJson("/screens/{$this->screen->id}", [
        'name' => 'Counter TV', 'orientation' => 'landscape', 'default_media_id' => $this->poster->id,
    ])->assertStatus(422)->assertJsonValidationErrors(['default_media_id' => 'That file was deleted meanwhile. Choose another one.']);

    expect($this->screen->fresh()->default_media_id)->toBeNull();
});

test('a screen deleted while its form was on its way is no longer here, and nothing is logged', function () {
    rightAfterTheHoldingPictureCheck(fn () => Screen::whereKey($this->screen->id)->delete());

    $this->putJson("/screens/{$this->screen->id}", [
        'name' => 'Counter TV', 'orientation' => 'landscape', 'default_media_id' => $this->poster->id,
    ])->assertNotFound();

    $this->assertDatabaseMissing('activity_logs', ['action' => 'screen.updated']);
});

test("an organization's delete holds its files, then its own channels, before its screens go", function () {
    PlaylistItem::create(['screen_id' => $this->screen->id, 'media_id' => $this->poster->id, 'position' => 0, 'duration_seconds' => 10]);
    PlaylistItem::create(['screen_id' => $this->screen->id, 'channel_id' => $this->channel->id, 'position' => 1]);
    $log = TransactionStatements::record();

    DB::transaction(fn () => $this->organization->delete());

    $statements = $log->of('/^delete from "screens"/');
    $files = statementAt($statements, '/^select "id" from "media" where "organization_id" = \? order by "id" asc/');
    $channels = statementAt($statements, '/^select "id" from "channels" where "organization_id" = \? order by "id" asc/');
    $screens = statementAt($statements, '/^delete from "screens"/');

    // Its own row first, as every change to its team or its library takes it; then the files and channels a save holds
    // before its screens — the order a save takes them in.
    expect($statements[0])->toStartWith('select "id" from "organizations" where "organizations"."id" = ?')
        ->and(TransactionStatements::locksForUpdate($statements[0]))->toBeTrue()
        ->and($files)->toBe(1)
        ->and(TransactionStatements::locksForUpdate($statements[1]))->toBeTrue()
        ->and($channels)->toBe(2)
        ->and(TransactionStatements::locksForUpdate($statements[2]))->toBeTrue()
        ->and($screens)->toBeGreaterThan($channels);

    expect(Screen::count())->toBe(0)
        ->and(Media::where('organization_id', $this->organization->id)->count())->toBe(0)
        ->and(Channel::where('organization_id', $this->organization->id)->count())->toBe(0)
        ->and(PlaylistItem::count())->toBe(0);
});
