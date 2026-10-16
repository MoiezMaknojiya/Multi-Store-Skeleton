<?php

namespace Tests\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;

/**
 * The test suites run on SQLite, which has no row locks, so Laravel writes none into its statements. This grammar
 * writes each lock as an SQL comment instead — "for update" or "for share" between the comment marks, at the end of
 * the statement or of the subquery that asks for it — so a test can read, in the statements a request runs, which
 * reads would lock on MySQL and in what order (TransactionStatements). SQLite skips a comment like whitespace.
 */
final class LocksShownGrammar extends SQLiteGrammar
{
    /** @param  bool|string  $value */
    protected function compileLock(Builder $query, $value): string
    {
        return match (true) {
            is_string($value) => '/* '.$value.' */',
            $value => '/* for update */',
            default => '/* for share */',
        };
    }
}
