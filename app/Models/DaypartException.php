<?php

namespace App\Models;

use App\Models\Concerns\HasClockTimes;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * One weekday on which a daypart does not follow its usual hours.
 *
 * Both times null means the daypart is CLOSED that weekday — the one thing a pair of
 * times cannot say, and the reason these columns are nullable while the daypart's own
 * are not.
 */
class DaypartException extends Model
{
    use HasClockTimes;

    /** Replaced as a whole set on every save, so their own dates say nothing. */
    public $timestamps = false;

    protected $fillable = ['weekday', 'start_time', 'end_time'];

    protected function casts(): array
    {
        return ['weekday' => 'integer'];
    }

    protected function startTime(): Attribute
    {
        return self::clockTime();
    }

    protected function endTime(): Attribute
    {
        return self::clockTime();
    }

    /** True when this weekday is closed rather than merely different. */
    public function isClosed(): bool
    {
        return $this->start_time === null || $this->end_time === null;
    }
}
