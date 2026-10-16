<?php

namespace Tests\Support;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * The statements each transaction the app opens runs, in order, with its locks written in (LocksShownGrammar).
 *
 * MySQL's REPEATABLE READ answers every plain read of a transaction from the snapshot its first read took. A decision
 * made under a lock is therefore only as good as the order of the statements before it: the lock must come first,
 * or what decides must be read with a lock too. SQLite has neither snapshots nor locks, so a test cannot watch two
 * transactions race; it reads that order instead.
 */
final class TransactionStatements
{
    /** @var list<list<string>> */
    private array $transactions = [];

    /** Start recording: every transaction opened from now on, one level above the test's own (RefreshDatabase). */
    public static function record(): self
    {
        $log = new self;
        $connection = DB::connection();
        $connection->setQueryGrammar(new LocksShownGrammar($connection));
        $outside = DB::transactionLevel();

        Event::listen(TransactionBeginning::class, function () use ($log, $outside) {
            if (DB::transactionLevel() === $outside + 1) {
                $log->transactions[] = [];
            }
        });

        DB::listen(function (QueryExecuted $query) use ($log, $outside) {
            if ($log->transactions !== [] && DB::transactionLevel() > $outside) {
                $log->transactions[array_key_last($log->transactions)][] = $query->sql;
            }
        });

        return $log;
    }

    /**
     * The statements of the first transaction that ran one matching $pattern (a regular expression), in order.
     *
     * @return list<string>
     */
    public function of(string $pattern): array
    {
        foreach ($this->transactions as $statements) {
            if (preg_grep($pattern, $statements) !== []) {
                return $statements;
            }
        }

        return [];
    }

    public static function locksForUpdate(string $statement): bool
    {
        return str_ends_with($statement, '/* for update */');
    }

    public static function locksForShare(string $statement): bool
    {
        return str_ends_with($statement, '/* for share */');
    }
}
