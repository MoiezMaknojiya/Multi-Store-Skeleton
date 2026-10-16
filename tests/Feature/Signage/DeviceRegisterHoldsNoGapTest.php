<?php

use App\Models\PairingRequest;
use App\Services\DevicePairing;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Support\TransactionStatements;

/*
|--------------------------------------------------------------------------
| A register never holds a gap
|--------------------------------------------------------------------------
|
| On MySQL a locking read of a key that is not there holds the gap the key would go in, and two registers holding one
| gap deadlocked on their inserts (the second review of 2026-10-15): a 1213, a 500 on the open device API — any two new
| sets at once while pairing_requests is nearly empty (every key then falls in one gap), and every set asking again
| once its code expired, whose row the prune had just taken. SQLite has no gap locks, so these tests read which
| statements the register's transaction runs (TransactionStatements); and play the other register, which landed at the
| same moment, where it would hurt.
|
*/

/** Runs $meanwhile once, right after the register has looked for $uuid and before its transaction opens. */
function rightAfterTheLookFor(string $uuid, Closure $meanwhile, int $times = 1): void
{
    $done = 0;
    $busy = false;

    DB::listen(function (QueryExecuted $query) use ($uuid, $meanwhile, $times, &$done, &$busy) {
        // Not again for the statements $meanwhile runs itself.
        if ($busy || $done >= $times) {
            return;
        }

        if (str_starts_with($query->sql, 'select exists(select * from "pairing_requests" where "device_uuid" = ?') && $query->bindings === [$uuid]) {
            $done++;
            $busy = true;
            $meanwhile();
            $busy = false;
        }
    });
}

/** A request of another register's, as it would be on file had it committed a moment before. */
function anotherRegistersRequest(string $uuid): PairingRequest
{
    return PairingRequest::create([
        'device_uuid' => $uuid,
        'code' => 'ZZZZ22',
        'poll_secret_hash' => hash('sha256', 'their-secret'),
        'expires_at' => now()->addMinutes(15),
    ]);
}

test('a uuid with no request on file is inserted, never read under a lock first: a new set and one whose code expired', function () {
    $log = TransactionStatements::record();
    $uuid = (string) Str::uuid();

    $first = $this->postJson('/device/register', ['device_uuid' => $uuid])->assertOk()->json();

    expect($first['device_uuid'])->toBe($uuid);
    $inserting = $log->of('/^insert into "pairing_requests"/');
    expect($inserting)->not->toBeEmpty();
    foreach ($inserting as $statement) {
        expect(TransactionStatements::locksForUpdate($statement) || TransactionStatements::locksForShare($statement))
            ->toBeFalse("A locking read where no row may be: {$statement}");
    }

    // Its code expires; the prune takes the row the moment the set asks again, with its uuid and its secret.
    $this->travel(16)->minutes();
    $log = TransactionStatements::record();

    $again = $this->postJson('/device/register', ['device_uuid' => $uuid, 'poll_secret' => $first['poll_secret']])->assertOk()->json();

    expect($again['device_uuid'])->toBe($uuid)->and($again['code'])->not->toBe($first['code']);
    foreach ($log->of('/^insert into "pairing_requests"/') as $statement) {
        expect(TransactionStatements::locksForUpdate($statement))->toBeFalse("A locking read where no row may be: {$statement}");
    }
});

test('a uuid with a request on file is still read under its lock first, as a claim holds it', function () {
    $device = $this->postJson('/device/register')->assertOk()->json();
    $log = TransactionStatements::record();

    $again = $this->postJson('/device/register', ['device_uuid' => $device['device_uuid'], 'poll_secret' => $device['poll_secret']])
        ->assertOk()->json();

    expect($again['code'])->toBe($device['code']);
    $statements = $log->of('/from "pairing_requests" where "device_uuid" = \?/');
    expect($statements[0])->toStartWith('select * from "pairing_requests" where "device_uuid" = ?')
        ->and(TransactionStatements::locksForUpdate($statements[0]))->toBeTrue();
});

test('another register of the same uuid landing after the look meets the unique key: the next try finds it, and gets a code of its own', function () {
    $uuid = (string) Str::uuid();
    rightAfterTheLookFor($uuid, fn () => anotherRegistersRequest($uuid));

    $answer = $this->postJson('/device/register', ['device_uuid' => $uuid])->assertOk()->json();

    // Theirs stays theirs: no secret was sent for it, so this one is a request of its own.
    expect($answer['device_uuid'])->not->toBe($uuid)
        ->and($answer['code'])->not->toBe('ZZZZ22');
    expect(PairingRequest::where('device_uuid', $uuid)->firstOrFail())
        ->code->toBe('ZZZZ22')
        ->poll_secret_hash->toBe(hash('sha256', 'their-secret'));
    $this->assertDatabaseCount('pairing_requests', 2);
});

test('a request gone between the look and the lock ends the try with nothing written, and the next one inserts it', function () {
    $device = $this->postJson('/device/register')->assertOk()->json();

    // Its device collected the token, say: the row goes the moment after this register saw it.
    rightAfterTheLookFor($device['device_uuid'], fn () => PairingRequest::where('device_uuid', $device['device_uuid'])->delete());

    $answer = $this->postJson('/device/register', ['device_uuid' => $device['device_uuid']])->assertOk()->json();

    expect($answer['device_uuid'])->toBe($device['device_uuid'])
        ->and(PairingRequest::where('device_uuid', $device['device_uuid'])->value('code'))->toBe($answer['code']);
    $this->assertDatabaseCount('pairing_requests', 1);
});

test('a register made the victim of a deadlock tries again, and only for a deadlock', function () {
    // At the top level, as a request runs it: on a connection of its own, made the default for this test alone, outside the
    // suite's own transaction (RefreshDatabase) — inside a caller's transaction a deadlock is the caller's to handle.
    $suite = config('database.default');
    config(['database.connections.register_probe' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
    DB::purge('register_probe');
    config(['database.default' => 'register_probe']);

    try {
        Schema::create('screens', function (Blueprint $table) {
            $table->id();
            $table->string('device_uuid', 40)->nullable();
        });
        (require database_path('migrations/2026_10_01_201000_create_pairing_requests_table.php'))->up();

        // The same uuid inserted twice at once just after the prune took it: InnoDB's duplicate check makes one a victim.
        $failures = ['SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'];
        DB::connection('register_probe')->beforeExecuting(function (string $query, array $bindings) use (&$failures) {
            if ($failures !== [] && str_starts_with($query, 'insert into "pairing_requests"')) {
                $cause = new PDOException(array_shift($failures));
                $cause->errorInfo = str_contains($cause->getMessage(), '1213') ? ['40001', 1213, 'Deadlock found when trying to get lock'] : ['HY000', 1205, 'Lock wait timeout exceeded'];

                throw new QueryException('register_probe', $query, $bindings, $cause);
            }
        });

        $uuid = (string) Str::uuid();
        $answer = app(DevicePairing::class)->register($uuid);

        expect(DB::transactionLevel())->toBe(0)
            ->and($answer['device_uuid'])->toBe($uuid)
            ->and(PairingRequest::where('device_uuid', $uuid)->value('code'))->toBe($answer['code']);

        // A lock that waited too long is no deadlock: it is not tried again.
        $failures = ['SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction'];

        expect(fn () => app(DevicePairing::class)->register((string) Str::uuid()))->toThrow(QueryException::class, 'Lock wait timeout');
    } finally {
        config(['database.default' => $suite]);
    }
});

test('a uuid that comes and goes at every try still gets an answer: a request of its own, never a 500', function () {
    $uuid = (string) Str::uuid();

    // Inserted after the first look, gone after the second, inserted again after the third.
    rightAfterTheLookFor($uuid, function () use ($uuid) {
        $on = PairingRequest::where('device_uuid', $uuid)->exists();
        $on ? PairingRequest::where('device_uuid', $uuid)->delete() : anotherRegistersRequest($uuid);
    }, times: 3);

    $answer = $this->postJson('/device/register', ['device_uuid' => $uuid])->assertOk()->json();

    expect($answer['device_uuid'])->not->toBe($uuid)
        ->and(PairingRequest::where('device_uuid', $answer['device_uuid'])->value('code'))->toBe($answer['code'])
        ->and(PairingRequest::where('device_uuid', $uuid)->value('code'))->toBe('ZZZZ22');
});
