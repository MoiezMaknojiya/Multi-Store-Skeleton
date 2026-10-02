<?php

namespace App\Models;

use App\Models\Concerns\HasClockTimes;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * When one item on one screen is allowed to play.
 *
 * A rule answers two questions that are kept deliberately apart:
 *
 *   WHICH DAYS   a date range, and optionally a repeat — every Friday, the third
 *                Thursday of the month, every year on the 14th.
 *   WHAT TIME    from one clock time to another, typed on the rule itself (owner, 2026-10-01: the
 *                named dayparts a rule used to point at are gone, docs/SCHEDULE-SPEC.md §21). Both
 *                empty means the whole day; an end before the start runs past midnight.
 *
 * Folding those two into one control is what forces the competing product to have
 * a separate modal for each; kept apart, "every Friday at lunch" is one row.
 *
 * An item may carry several rules and plays if ANY of them says yes, so "Eid
 * evenings AND every Friday lunchtime" is two rows rather than a special case — and so is
 * "weekdays 07:00 to 20:00, Sunday 09:00 to 16:00".
 *
 * The times are WALL CLOCK, read in the screen's own timezone: "07:00" is seven in the morning
 * where that television stands, which is why the daylight-saving switch needs no handling at all.
 */
class ScheduleRule extends Model
{
    use HasClockTimes;

    /** ISO weekdays, the one list the schedule window and the resolver share. Keys match
     *  Carbon's dayOfWeekIso, so nothing ever has to be converted. */
    public const WEEKDAYS = [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday',
    ];

    public const DAILY = 'daily';

    public const WEEKLY = 'weekly';

    public const MONTHLY_DAY = 'monthly_day';

    public const MONTHLY_WEEKDAY = 'monthly_weekday';

    public const YEARLY = 'yearly';

    /** The repeat patterns, and what each of them needs filled in. */
    public const TYPES = [
        self::DAILY => 'Every day',
        self::WEEKLY => 'Every week',
        self::MONTHLY_DAY => 'Every month, on a date',
        self::MONTHLY_WEEKDAY => 'Every month, on a weekday',
        self::YEARLY => 'Every year',
    ];

    /** "The third Thursday", and the one that has to be counted backwards. */
    public const ORDINALS = [
        1 => 'first',
        2 => 'second',
        3 => 'third',
        4 => 'fourth',
        -1 => 'last',
    ];

    protected $fillable = [
        'playlist_item_id', 'start_time', 'end_time', 'starts_on', 'ends_on',
        'recurrence_type', 'recurrence_interval', 'recurrence_weekdays',
        'recurrence_monthday', 'recurrence_ordinal', 'recurrence_weekday',
        'recurrence_until', 'position',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'recurrence_until' => 'date',
            'recurrence_weekdays' => 'array',
            'recurrence_interval' => 'integer',
            'recurrence_monthday' => 'integer',
            'recurrence_ordinal' => 'integer',
            'recurrence_weekday' => 'integer',
            'position' => 'integer',
        ];
    }

    protected function startTime(): Attribute
    {
        return self::clockTime();
    }

    protected function endTime(): Attribute
    {
        return self::clockTime();
    }

    /** Whether the rule keeps hours of its own. With none it covers the whole of every day it covers. */
    public function hasTimes(): bool
    {
        return $this->start_time !== null && $this->end_time !== null;
    }

    /** An end before the start means the hours run past midnight — 22:00 to 02:00. */
    public function crossesMidnight(): bool
    {
        return $this->hasTimes() && $this->end_time <= $this->start_time;
    }

    /**
     * The clock times at which this rule's answer can change, as "H:i" — the moments a television's
     * answer can change at (the offline timeline, docs/AD-BUILDER-SPEC.md §15).
     *
     * @return list<string>
     */
    public function clockTimes(): array
    {
        return $this->hasTimes() ? [$this->start_time, $this->end_time] : [];
    }

    /**
     * Does this rule allow the item to play at this moment?
     *
     * The moment must already be in the screen's timezone.
     *
     * The subtle part is which DAY the day-rule is tested against. With hours that
     * run past midnight, half past midnight on Saturday belongs to Friday's
     * hours — the organization said "Friday night, 22:00 to 02:00" and meant it. So the
     * rule is asked which day the hours open now began on, and its days are checked
     * against THAT, not against the calendar date.
     */
    public function coversAt(CarbonInterface $moment): bool
    {
        $at = CarbonImmutable::instance($moment);

        if (! $this->hasTimes()) {
            return $this->coversDay($at->startOfDay());
        }

        $serviceDay = $this->openWindowDay($at);

        return $serviceDay !== null && $this->coversDay($serviceDay);
    }

    /**
     * WHICH DAY the hours open at this moment belong to — or null when the clock is outside them.
     *
     * Two days' hours can cover "now": today's, and yesterday's that have not closed yet because
     * they run past midnight. Checking only today's is the bug that makes "22:00–02:00" go dark at
     * midnight.
     */
    private function openWindowDay(CarbonImmutable $at): ?CarbonImmutable
    {
        $time = $at->format('H:i');
        [$start, $end] = [$this->start_time, $this->end_time];

        if ($end > $start) {
            return $time >= $start && $time < $end ? $at->startOfDay() : null;
        }

        // Past midnight: today's hours run to the end of the day, and yesterday's reach into this morning.
        if ($time >= $start) {
            return $at->startOfDay();
        }

        return $time < $end ? $at->subDay()->startOfDay() : null;
    }

    /**
     * Does the day-half of this rule cover one particular date?
     *
     * Public because the preview walks a week of dates through it, and because it
     * is the half worth testing on its own.
     */
    public function coversDay(CarbonInterface $moment): bool
    {
        // Remembered per date on this very instance: a manifest's timeline asks the same rule about the same
        // day at every change point (docs/AD-BUILDER-SPEC.md §15), and the answer depends on nothing else.
        // A rule changed and saved is a new instance by the time anybody asks again.
        $date = CarbonImmutable::instance($moment)->toDateString();

        return $this->coveredDays[$date] ??= $this->computeCoversDay($date);
    }

    /** @var array<string, bool> coversDay()'s answers, by date */
    private array $coveredDays = [];

    /** A changed rule forgets what it used to cover. */
    public function setAttribute($key, $value)
    {
        $this->coveredDays = [];

        return parent::setAttribute($key, $value);
    }

    private function computeCoversDay(string $date): bool
    {
        $day = CarbonImmutable::parse($date);

        // The outer bounds first: cheap, and they end most calls.
        if ($this->starts_on && $day->lt($this->bareDate($this->starts_on))) {
            return false;
        }

        if ($this->ends_on && $day->gt($this->bareDate($this->ends_on))) {
            return false;
        }

        if ($this->recurrence_until && $day->gt($this->bareDate($this->recurrence_until))) {
            return false;
        }

        // No repeat: the range above is the whole rule.
        if ($this->recurrence_type === null) {
            return true;
        }

        // Every repeat is counted from somewhere. starts_on is that somewhere; with
        // none, "every 2 weeks" has nothing to be every-2-weeks FROM, so the rule's
        // own creation date stands in.
        $anchor = $this->bareDate($this->starts_on ?? $this->created_at ?? $day);
        $interval = max(1, (int) $this->recurrence_interval);

        return match ($this->recurrence_type) {
            self::DAILY => self::daysBetween($anchor, $day) % $interval === 0,
            self::WEEKLY => $this->weeklyCovers($day, $anchor, $interval),
            self::MONTHLY_DAY => $this->monthlyDayCovers($day, $anchor, $interval),
            self::MONTHLY_WEEKDAY => $this->monthlyWeekdayCovers($day, $anchor, $interval),
            self::YEARLY => $this->yearlyCovers($day, $anchor, $interval),
            default => false,
        };
    }

    /**
     * The times this rule would put the item on screen over the next few days.
     *
     * Feeds the "next 7 days" line under the editor. It runs through the very same
     * coversDay() the device does, so the preview cannot drift away from reality —
     * which is the whole reason it is computed here and not in the browser.
     *
     * @return array<int, array{date: string, start: ?string, end: ?string, crosses_midnight: bool}>
     */
    public function occurrences(CarbonInterface $from, int $days = 7): array
    {
        $start = CarbonImmutable::parse(CarbonImmutable::instance($from)->toDateString());
        $found = [];

        foreach (range(0, max(0, $days - 1)) as $offset) {
            $day = $start->addDays($offset);

            if (! $this->coversDay($day)) {
                continue;
            }

            // With no hours of its own the item is eligible for the whole day; with them, only inside them.
            $found[] = [
                'date' => $day->toDateString(),
                'start' => $this->hasTimes() ? $this->start_time : null,
                'end' => $this->hasTimes() ? $this->end_time : null,
                'crosses_midnight' => $this->crossesMidnight(),
            ];
        }

        return $found;
    }

    /* ── The repeat patterns ──────────────────────────────────────────────── */

    private function weeklyCovers(CarbonImmutable $day, CarbonImmutable $anchor, int $interval): bool
    {
        $weekdays = array_map('intval', $this->recurrence_weekdays ?? []);

        if (! in_array($day->dayOfWeekIso, $weekdays, true)) {
            return false;
        }

        if ($interval === 1) {
            return true;
        }

        // Counted in whole weeks from the anchor's week, so "every 2 weeks" lands on
        // the same pair of weeks no matter which day inside them is being asked about.
        $weeks = intdiv(
            self::daysBetween(
                $anchor->startOfWeek(CarbonInterface::MONDAY),
                $day->startOfWeek(CarbonInterface::MONDAY)
            ),
            7
        );

        return $weeks % $interval === 0;
    }

    private function monthlyDayCovers(CarbonImmutable $day, CarbonImmutable $anchor, int $interval): bool
    {
        // A 31st simply does not occur in a 30-day month. Clamping it to the 30th
        // would put the item on a day the organization never asked for.
        if ($day->day !== (int) $this->recurrence_monthday) {
            return false;
        }

        return self::monthsBetween($anchor, $day) % $interval === 0;
    }

    private function monthlyWeekdayCovers(CarbonImmutable $day, CarbonImmutable $anchor, int $interval): bool
    {
        if ($day->dayOfWeekIso !== (int) $this->recurrence_weekday) {
            return false;
        }

        $ordinal = (int) $this->recurrence_ordinal;

        // Which occurrence of that weekday this is: days 1-7 are the first, 8-14 the
        // second, and so on. "Last" is whichever one has no successor in the month.
        $matches = $ordinal === -1
            ? $day->addDays(7)->month !== $day->month
            : intdiv($day->day - 1, 7) + 1 === $ordinal;

        return $matches && self::monthsBetween($anchor, $day) % $interval === 0;
    }

    private function yearlyCovers(CarbonImmutable $day, CarbonImmutable $anchor, int $interval): bool
    {
        if ($day->month !== $anchor->month || $day->day !== $anchor->day) {
            return false;
        }

        return ($day->year - $anchor->year) % $interval === 0;
    }

    /**
     * Everything about this rule that a person could have changed, as one string.
     *
     * Feeds Screen::playlistFingerprint, which is how a stale save is caught. The
     * id and the timestamps are left out on purpose: re-saving a rule that says the
     * same thing is not a conflict, because nothing was lost.
     */
    public function fingerprint(): string
    {
        return implode(',', [
            $this->start_time,
            $this->end_time,
            $this->starts_on?->toDateString(),
            $this->ends_on?->toDateString(),
            $this->recurrence_type,
            $this->recurrence_interval,
            implode('.', $this->recurrence_weekdays ?? []),
            $this->recurrence_monthday,
            $this->recurrence_ordinal,
            $this->recurrence_weekday,
            $this->recurrence_until?->toDateString(),
        ]);
    }

    /* ── Date arithmetic ──────────────────────────────────────────────────── */

    /**
     * A bare calendar date, with no time and no zone.
     *
     * Everything here compares DAYS. Keeping a time or a timezone on either side is
     * how an off-by-one creeps in around a daylight-saving change.
     */
    private function bareDate(mixed $value): CarbonImmutable
    {
        return CarbonImmutable::parse(CarbonImmutable::instance(
            $value instanceof CarbonInterface ? $value : CarbonImmutable::parse((string) $value)
        )->toDateString());
    }

    /** Whole days between two bare dates — never negative, never off by a DST hour. */
    private static function daysBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) abs($from->diffInDays($to));
    }

    private static function monthsBetween(CarbonImmutable $from, CarbonImmutable $to): int
    {
        return abs(($to->year - $from->year) * 12 + ($to->month - $from->month));
    }
}
