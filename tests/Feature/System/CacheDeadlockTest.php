<?php

use App\Cache\DeadlockRetryingDatabaseStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| The database cache when InnoDB picks it as a deadlock's victim
|--------------------------------------------------------------------------
|
| Live logged "1213 Deadlock found when trying to get lock" on `insert ignore into cache … :timer` about once a day
| (2026-10-11, 10-12): the rate limiter's per-address ceiling of the device API is one pair of rows for every
| television behind an organization's one address. On MySQL, twelve processes on one key gave 18 deadlocks in 2628 hits
| with Laravel's own store and none with this one, every hit counted (storage/app/private/qa/2026-10-14/deadlock).
|
| The store under test is built from the app's own `database` store config, on a connection of its own: the suite's
| transaction (RefreshDatabase) is on the default connection, and inside a transaction the caller opened nothing is
| tried again, by design. A deadlock is thrown before a statement runs, as MySQL's would come out of it.
|
*/

beforeEach(function () {
    config(['database.connections.cache_probe' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    DB::purge('cache_probe');
    Schema::connection('cache_probe')->create('cache', function (Blueprint $table) {
        $table->string('key')->primary();
        $table->mediumText('value');
        $table->bigInteger('expiration')->index();
    });

    $this->store = Cache::build([...config('cache.stores.database'), 'connection' => 'cache_probe']);
    $this->statements = new ArrayObject;
});

/** Each statement whose words start with $start fails as $failure, $times times in all; every statement run is noted. */
function failStatements(string $start, int $times, string $failure = 'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction'): void
{
    $left = $times;
    DB::connection('cache_probe')->beforeExecuting(function (string $query, array $bindings) use ($start, &$left, $failure) {
        test()->statements->append($query);
        if ($left > 0 && str_starts_with($query, $start)) {
            $left--;
            $cause = new PDOException($failure);
            $cause->errorInfo = str_contains($failure, '1213') ? ['40001', 1213, 'Deadlock found when trying to get lock'] : ['HY000', 1205, $failure];
            throw new QueryException('cache_probe', $query, $bindings, $cause);
        }
    });
}

test('the app’s database cache is the one that tries a deadlock’s victim again', function () {
    expect($this->store->getStore())->toBeInstanceOf(DeadlockRetryingDatabaseStore::class);
});

test('a hit of the rate limiter that loses a deadlock is counted once, and the request goes on', function () {
    $limiter = new RateLimiter($this->store);
    failStatements('insert', 1);

    expect($limiter->hit('device-api:ip:203.0.113.7'))->toBe(1)
        ->and($limiter->hit('device-api:ip:203.0.113.7'))->toBe(2)
        ->and($limiter->attempts('device-api:ip:203.0.113.7'))->toBe(2);
});

test('every kind of cache call is tried again after a deadlock', function () {
    failStatements('select', 1);
    expect($this->store->get('missing'))->toBeNull();

    failStatements('insert', 1);
    expect($this->store->put('menu', 'breakfast', 60))->toBeTrue()
        ->and($this->store->get('menu'))->toBe('breakfast');

    failStatements('update', 1);
    expect($this->store->increment('count-that-is-not-there'))->toBeFalse();
    $this->store->put('count', 1, 60);
    expect($this->store->increment('count', 2))->toBe(3);

    failStatements('delete', 1);
    expect($this->store->forget('menu'))->toBeTrue()
        ->and($this->store->get('menu'))->toBeNull();
});

test('a deadlock on every try is given up after three, with MySQL’s own error', function () {
    failStatements('insert', 99);

    expect(fn () => $this->store->add('menu', 'breakfast', 60))->toThrow(QueryException::class, 'Deadlock found');
    expect(collect($this->statements->getArrayCopy())->filter(fn (string $query) => str_starts_with($query, 'insert'))->count())
        ->toBe(DeadlockRetryingDatabaseStore::ATTEMPTS);
});

test('a lock that was waited for in vain is not waited for again', function () {
    failStatements('insert', 1, 'SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction');

    expect(fn () => $this->store->add('menu', 'breakfast', 60))->toThrow(QueryException::class, 'Lock wait timeout');
    expect(collect($this->statements->getArrayCopy())->filter(fn (string $query) => str_starts_with($query, 'insert'))->count())->toBe(1);
});

test('inside a transaction the caller opened, a deadlock is the caller’s: InnoDB rolled the whole of it back', function () {
    failStatements('insert', 1);
    DB::connection('cache_probe')->beginTransaction();

    try {
        expect(fn () => $this->store->add('menu', 'breakfast', 60))->toThrow(QueryException::class, 'Deadlock found');
    } finally {
        DB::connection('cache_probe')->rollBack();
    }
    expect(collect($this->statements->getArrayCopy())->filter(fn (string $query) => str_starts_with($query, 'insert'))->count())->toBe(1);
});
