<?php

use App\Models\Media;
use App\Models\Organization;
use App\Models\PlaylistItem;
use App\Models\Screen;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| A write of lines made the victim of a deadlock runs again
|--------------------------------------------------------------------------
|
| A playlist's save and Copy to Other Screens take their row locks in one order (LinesTakeTheirLocksInOrderTest), but
| they replace the lines: a delete, then inserts. On MySQL that delete holds the gaps of the lines' (screen_id, position)
| index, and through the foreign key of their schedule rules', beyond the screen's own rows — two saves of neighbouring
| screens with no lines between them hold one gap and each one's insert waits on the other's (the third review of
| 2026-10-15). InnoDB undoes one of them with a 1213; it was a 500. Now it runs again (PlaylistController::inTurn) —
| for a deadlock alone: a lock-wait timeout has already waited 50 seconds, and is not waited for three times.
|
| SQLite has no gap locks, so the deadlock is InnoDB's own error, thrown where it would be. And at the top level, as a
| request runs it: on a connection of its own, made the default for these tests alone, outside the suite's own
| transaction (RefreshDatabase) — inside a caller's transaction Laravel hands a deadlock to the caller.
|
*/

beforeEach(function () {
    Storage::fake('public');

    $this->suiteConnection = config('database.default');
    config(['database.connections.lines_probe' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
    DB::purge('lines_probe');
    config(['database.default' => 'lines_probe']);
    Artisan::call('migrate', ['--force' => true]);

    $this->organization = Organization::factory()->create(['name' => 'Alpha Mart']);
    $this->actor = createOrganizationUser($this->organization, ['screen-view', 'screen-playlist']);
    $this->actingAs($this->actor)->withSession(['current_organization_id' => $this->organization->id]);

    $this->screen = Screen::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Counter TV']);
    $this->poster = Media::factory()->create(['organization_id' => $this->organization->id, 'title' => 'Poster']);
    $this->menu = Media::factory()->create(['organization_id' => $this->organization->id, 'title' => 'Menu']);
    PlaylistItem::create(['screen_id' => $this->screen->id, 'media_id' => $this->menu->id, 'position' => 0, 'duration_seconds' => 10]);

    $this->linesTried = 0;
});

afterEach(function () {
    // Back before the suite's transaction is rolled back, which it finds on the default connection.
    config(['database.default' => $this->suiteConnection]);
    DB::purge('lines_probe');
});

/**
 * The next inserts of a line fail with InnoDB's $errors, one each — 'deadlock' (1213) or 'timeout' (1205) — as InnoDB
 * fails the statement it undoes a transaction at; every insert of a line tried is counted in $tried.
 *
 * @param  list<string>  $errors
 */
function failTheNextLineInserts(array $errors, int &$tried): void
{
    $said = [
        'deadlock' => ['40001', 1213, 'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'],
        'timeout' => ['HY000', 1205, 'SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction'],
    ];

    DB::connection()->beforeExecuting(function (string $query, array $bindings) use (&$errors, &$tried, $said) {
        if (! str_starts_with($query, 'insert into "playlist_items"')) {
            return;
        }

        $tried++;

        if ($errors === []) {
            return;
        }

        [$state, $code, $message] = $said[array_shift($errors)];
        $cause = new PDOException($message);
        $cause->errorInfo = [$state, $code, $message];

        throw new QueryException(DB::getDefaultConnection(), $query, $bindings, $cause);
    });
}

/** What the screen's lines are, in order, as the television is sent them. */
function linesOf(Screen $screen): array
{
    return $screen->playlistItems()->orderBy('position')->pluck('media_id')->map(fn (int|string $id) => (int) $id)->all();
}

test('a save made the victim of a deadlock runs again, and writes its lines once', function () {
    failTheNextLineInserts(['deadlock'], $this->linesTried);

    $answer = $this->putJson("/screens/{$this->screen->id}/playlist", ['version' => $this->screen->playlistFingerprint(), 'items' => [
        ['media_id' => $this->poster->id, 'duration_seconds' => 10, 'rules' => []],
    ]])->assertOk();

    expect($this->linesTried)->toBe(2)
        ->and(linesOf($this->screen))->toBe([$this->poster->id])
        ->and($answer->json('version'))->toBe($this->screen->fresh()->playlistFingerprint());
    $this->assertDatabaseCount('activity_logs', 1);
});

test('a copy made the victim of a deadlock runs again, and every target gets the lines once', function () {
    $window = Screen::factory()->create(['organization_id' => $this->organization->id, 'name' => 'Window TV']);
    failTheNextLineInserts(['deadlock', 'deadlock'], $this->linesTried);

    $this->postJson("/screens/{$this->screen->id}/playlist/copy", ['target_screen_ids' => [$window->id]])->assertOk();

    expect($this->linesTried)->toBe(3)
        ->and(linesOf($window))->toBe([$this->menu->id]);
});

test('a lock-wait timeout is not waited for again: the save is refused at once and the lines stay as they were', function () {
    failTheNextLineInserts(['timeout'], $this->linesTried);

    $this->putJson("/screens/{$this->screen->id}/playlist", ['version' => $this->screen->playlistFingerprint(), 'items' => [
        ['media_id' => $this->poster->id, 'duration_seconds' => 10, 'rules' => []],
    ]])->assertStatus(500);

    expect($this->linesTried)->toBe(1)
        ->and(linesOf($this->screen))->toBe([$this->menu->id]);
});

test('a save that loses every try to a deadlock stops after three, and the lines stay as they were', function () {
    failTheNextLineInserts(['deadlock', 'deadlock', 'deadlock'], $this->linesTried);

    $this->putJson("/screens/{$this->screen->id}/playlist", ['version' => $this->screen->playlistFingerprint(), 'items' => [
        ['media_id' => $this->poster->id, 'duration_seconds' => 10, 'rules' => []],
    ]])->assertStatus(500);

    expect($this->linesTried)->toBe(3)
        ->and(linesOf($this->screen))->toBe([$this->menu->id]);
});
