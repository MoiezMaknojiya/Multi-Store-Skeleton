<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * One shape for a `time` column, whichever database is underneath.
 *
 * MySQL hands back "07:00:00" from a TIME column; SQLite hands back whatever was
 * written. Left alone, the same row would read differently in the test suite and in
 * production, and every comparison in the resolver would have to guess. So a clock
 * time is always "H:i" in PHP and always "H:i:s" in the database.
 */
trait HasClockTimes
{
    /**
     * The accessor/mutator pair for one clock column. Use it from an attribute method
     * named after the column: `protected function startTime(): Attribute { return
     * self::clockTime(); }`.
     */
    protected static function clockTime(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value === null ? null : substr($value, 0, 5),
            set: fn (?string $value) => ($value === null || $value === '')
                ? null
                : substr($value, 0, 5).':00',
        );
    }
}
