<?php

namespace App\Models;

use App\Models\Concerns\HasClockTimes;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named window of time — "Breakfast 07:00–11:00", "Deli hours 07:00–20:00".
 *
 * Named and reusable on purpose. The alternative, which the competing product uses,
 * is seven start/end rows retyped on every playlist item; here the shop writes "Deli hours" once, points
 * the lines that should follow them at it, and changing the hours for Ramadan is one edit in one place.
 *
 * The times are WALL CLOCK, read in the screen's own timezone. "07:00" means seven in
 * the morning where that television is standing, which is why the daylight-saving
 * switch needs no handling at all.
 */
class Daypart extends Model
{
    use HasClockTimes, HasFactory;

    /** ISO weekdays, the one list the exception dropdowns and the resolver share.
     *  Keys match Carbon's dayOfWeekIso so nothing ever has to be converted. */
    public const WEEKDAYS = [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday',
    ];

    protected $fillable = ['store_id', 'name', 'start_time', 'end_time', 'is_retired', 'created_by'];

    protected function casts(): array
    {
        return ['is_retired' => 'boolean'];
    }

    protected function startTime(): Attribute
    {
        return self::clockTime();
    }

    protected function endTime(): Attribute
    {
        return self::clockTime();
    }

    /**
     * Scoped to the STORE alone, exactly like media: a daypart is shop furniture — "Deli hours" belongs to
     * the deli, not to the person who typed it — and every colleague must be able to use it on a playlist.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->globalRole() !== null) {
            return $query;
        }

        $currentStoreId = session('current_store_id');

        if (! $currentStoreId) {
            return $query->whereRaw('0 = 1');
        }

        return $query->where('store_id', $currentStoreId);
    }

    /** The ones still offered in pickers. Retired dayparts keep working where they are
     *  already in use, but nothing new may be pointed at them. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_retired', false);
    }

    public function exceptions(): HasMany
    {
        return $this->hasMany(DaypartException::class)->orderBy('weekday');
    }

    /** The playlist rules that play an item inside this window. */
    public function scheduleRules(): HasMany
    {
        return $this->hasMany(ScheduleRule::class);
    }

    /** Anything still pointing at this daypart. The foreign key is nullOnDelete, so
     *  deleting one in use would not error — it would silently set a lunchtime advert
     *  playing all day, with nothing to show why. That is what "Retired" is for. */
    public function isInUse(): bool
    {
        return $this->scheduleRules()->exists();
    }

    /** An end before the start means the window runs past midnight — 22:00 to 02:00. */
    public function crossesMidnight(): bool
    {
        return $this->end_time <= $this->start_time;
    }

    /**
     * Every clock time at which this daypart can open or close, on any weekday — its own two and every
     * exception's — as "H:i". The moments a television's answer can change at (the offline timeline,
     * docs/AD-BUILDER-SPEC.md §15), so a superset is harmless and a missing one is not.
     *
     * @return list<string>
     */
    public function clockTimes(): array
    {
        return collect([$this->start_time, $this->end_time])
            ->merge($this->exceptions->flatMap(fn (DaypartException $exception) => [$exception->start_time, $exception->end_time]))
            ->filter(fn (?string $time) => is_string($time) && $time !== '')
            ->map(fn (string $time) => substr($time, 0, 5))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The window that opens on one ISO weekday (1 = Monday … 7 = Sunday), as
     * [start, end] in "H:i" — or null when the daypart is closed that day.
     */
    public function windowFor(int $isoWeekday): ?array
    {
        if (array_key_exists($isoWeekday, $this->windows)) {
            return $this->windows[$isoWeekday];
        }

        $exception = $this->exceptions->firstWhere('weekday', $isoWeekday);

        if ($exception) {
            return $this->windows[$isoWeekday] = $exception->isClosed() ? null : [$exception->start_time, $exception->end_time];
        }

        return $this->windows[$isoWeekday] = [$this->start_time, $this->end_time];
    }

    /**
     * windowFor()'s and openWindowDay()'s answers, remembered on this very instance: a manifest's timeline
     * asks the same daypart about the same minutes for every line that uses it (docs/AD-BUILDER-SPEC.md
     * §15). Forgotten whenever an attribute or a relation changes.
     *
     * @var array<int, array{0: string, 1: string}|null>
     */
    private array $windows = [];

    /** @var array<string, CarbonImmutable|null> */
    private array $openDays = [];

    public function setAttribute($key, $value)
    {
        $this->windows = $this->openDays = [];

        return parent::setAttribute($key, $value);
    }

    public function setRelation($relation, $value)
    {
        $this->windows = $this->openDays = [];

        return parent::setRelation($relation, $value);
    }

    public function unsetRelation($relation)
    {
        $this->windows = $this->openDays = [];

        return parent::unsetRelation($relation);
    }

    /**
     * Is this daypart open at the given moment?
     *
     * The moment must already be in the screen's timezone — this class knows about
     * clock faces, not about where in the world they hang.
     *
     * Two windows can cover "now": the one that opens today, and one that opened
     * YESTERDAY and has not closed yet because it runs past midnight. Checking only
     * today's is the bug that makes a "22:00–02:00" window go dark at midnight.
     */
    public function coversAt(CarbonInterface $moment): bool
    {
        return $this->openWindowDay($moment) !== null;
    }

    /**
     * WHICH DAY the currently-open window belongs to — or null if none is open.
     *
     * Not the same as the calendar date, and the difference matters. With a
     * 22:00–02:00 window, half past midnight on Saturday belongs to FRIDAY: the shop
     * said "Friday night" and meant it. A schedule rule that repeats on Fridays has
     * to be asked about Friday, not about the Saturday the clock happens to read.
     */
    public function openWindowDay(CarbonInterface $moment): ?CarbonImmutable
    {
        $at = CarbonImmutable::instance($moment);
        $minute = $at->format('Y-m-d H:i e');

        if (array_key_exists($minute, $this->openDays)) {
            return $this->openDays[$minute];
        }

        return $this->openDays[$minute] = $this->findOpenWindowDay($at);
    }

    private function findOpenWindowDay(CarbonImmutable $at): ?CarbonImmutable
    {
        $time = $at->format('H:i');

        $today = $this->windowFor($at->dayOfWeekIso);

        if ($today !== null) {
            [$start, $end] = $today;

            // An ordinary window closes the same day; one that crosses midnight simply
            // runs to the end of it, and the rest is picked up by tomorrow's check.
            $open = $end > $start
                ? ($time >= $start && $time < $end)
                : ($time >= $start);

            if ($open) {
                return $at->startOfDay();
            }
        }

        $yesterday = $at->subDay();
        $window = $this->windowFor($yesterday->dayOfWeekIso);

        if ($window !== null) {
            [$start, $end] = $window;

            if ($end <= $start && $time < $end) {
                return $yesterday->startOfDay();
            }
        }

        return null;
    }

    /**
     * Replace the whole set of exceptions in one go.
     *
     * Replaced rather than patched for the same reason the playlist is: reorder, add,
     * change and remove become one call, and a half-applied set of hours never reaches
     * a television.
     *
     * @param  array<int, array{weekday: int|string, start_time?: ?string, end_time?: ?string}>  $rows
     */
    public function syncExceptions(array $rows): void
    {
        $this->exceptions()->delete();

        foreach ($rows as $row) {
            $this->exceptions()->create([
                'weekday' => (int) $row['weekday'],
                'start_time' => $row['start_time'] ?? null,
                'end_time' => $row['end_time'] ?? null,
            ]);
        }

        $this->unsetRelation('exceptions');
    }
}
