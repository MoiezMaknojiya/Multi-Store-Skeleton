<?php

namespace App\Cache;

use Closure;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Database\QueryException;

/**
 * The database cache, with the loser of a deadlock tried again (owner, 2026-10-14 — "deadlock ka plan aur test bana do
 * aur theek kar do").
 *
 * The rate limiter keeps its counts here, and the televisions of one organization share one address, so the per-address
 * ceiling of the device API (`device-api:ip:…`) is the same two rows for all of them: when two sets ask in the same second,
 * one `insert ignore`, one `select … for update` and the delete of an expired row meet on those rows and InnoDB picks a
 * victim — a 1213 ("Deadlock found when trying to get lock"), a 500 for that one request, about once a day on live.
 * MySQL's own advice is to run the victim again ("Deadlocks are not dangerous. Just try again.", 17.7.5.3 How to Minimize
 * and Handle Deadlocks), which is what this does, and only this: the counting stays the database's, under its row lock,
 * so a burst of wrong passwords is still counted to the last one (a file store's increment is a read and a write apart).
 *
 * Only a deadlock is tried again — never a lock-wait timeout, which has already waited its 50 seconds — only from the
 * outermost call (an add reads first; the read is not tried on its own), and never inside a transaction the caller opened:
 * InnoDB rolls the WHOLE transaction back when it picks a victim, so running the cache's statement again there would leave
 * the caller writing on outside a transaction it thinks is still open. The parameters stay untyped, as the parent's are.
 */
class DeadlockRetryingDatabaseStore extends DatabaseStore
{
    /** Tries in all: the first and two more. */
    public const ATTEMPTS = 3;

    /** The pause before another try, in milliseconds, picked at random so two losers do not meet again. */
    public const PAUSE_MS = [5, 40];

    private bool $trying = false;

    public function get($key): mixed
    {
        return $this->tryAgainOnDeadlock(fn () => parent::get($key));
    }

    public function many(array $keys): array
    {
        return $this->tryAgainOnDeadlock(fn () => parent::many($keys));
    }

    public function put($key, $value, $seconds): bool
    {
        return $this->tryAgainOnDeadlock(fn () => parent::put($key, $value, $seconds));
    }

    public function putMany(array $values, $seconds): bool
    {
        return $this->tryAgainOnDeadlock(fn () => parent::putMany($values, $seconds));
    }

    public function add($key, $value, $seconds): bool
    {
        return $this->tryAgainOnDeadlock(fn () => parent::add($key, $value, $seconds));
    }

    public function increment($key, $value = 1): int|float|false
    {
        return $this->tryAgainOnDeadlock(fn () => parent::increment($key, $value));
    }

    public function decrement($key, $value = 1): int|float|false
    {
        return $this->tryAgainOnDeadlock(fn () => parent::decrement($key, $value));
    }

    public function forever($key, $value): bool
    {
        return $this->tryAgainOnDeadlock(fn () => parent::forever($key, $value));
    }

    public function touch($key, $seconds): bool
    {
        return $this->tryAgainOnDeadlock(fn () => parent::touch($key, $seconds));
    }

    public function forget($key): bool
    {
        return $this->tryAgainOnDeadlock(fn () => parent::forget($key));
    }

    public function forgetIfExpired($key): bool
    {
        return $this->tryAgainOnDeadlock(fn () => parent::forgetIfExpired($key));
    }

    public function flush(): bool
    {
        return $this->tryAgainOnDeadlock(fn () => parent::flush());
    }

    /** The operation, run again while InnoDB makes it a deadlock's victim, up to ATTEMPTS times in all. */
    private function tryAgainOnDeadlock(Closure $operation): mixed
    {
        if ($this->trying || $this->getConnection()->transactionLevel() > 0) {
            return $operation();
        }

        $this->trying = true;

        try {
            for ($attempt = 1; ; $attempt++) {
                try {
                    return $operation();
                } catch (QueryException $e) {
                    if ($attempt >= self::ATTEMPTS || ! self::isADeadlock($e)) {
                        throw $e;
                    }

                    usleep(random_int(...self::PAUSE_MS) * 1000);
                }
            }
        } finally {
            $this->trying = false;
        }
    }

    /** MySQL's 1213, SQLSTATE 40001 — and not "Lock wait timeout exceeded", which Laravel's own detector counts too. */
    public static function isADeadlock(QueryException $e): bool
    {
        $errorInfo = $e->errorInfo ?? [];

        return ($errorInfo[1] ?? null) === 1213
            || str_contains($e->getMessage(), 'Deadlock found when trying to get lock');
    }
}
