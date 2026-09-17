<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * When one item on one screen is allowed to play.
 *
 * A rule answers two questions that are kept deliberately apart:
 *
 *   WHICH DAYS   a date range, and optionally a repeat — every Friday, the third
 *                Thursday of the month, every year on the 14th.
 *   WHAT TIME    a daypart. Null means the whole day.
 *
 * Folding those two into one control is what forces the competing product to have
 * a separate modal for each; kept apart, "every Friday at lunch" is one row.
 *
 * An item may carry several rules and plays if ANY of them says yes, so "Eid
 * evenings AND every Friday lunchtime" is two rows rather than a special case.
 */
class ScheduleRule extends Model
{
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
        'playlist_item_id', 'daypart_id', 'starts_on', 'ends_on',
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

    public function daypart(): BelongsTo
    {
        return $this->belongsTo(Daypart::class);
    }

    /**
     * Does this rule allow the item to play at this moment?
     *
     * The moment must already be in the screen's timezone.
     *
     * The subtle part is which DAY the day-rule is tested against. With a window
     * that runs past midnight, half past midnight on Saturday belongs to Friday's
     * window — the shop said "Friday night, 22:00 to 02:00" and meant it. So the
     * daypart is asked which day the currently-open window began on, and the day
     * rule is checked against THAT, not against the calendar date.
     */
    public function coversAt(CarbonInterface $moment): bool
    {
        $at = CarbonImmutable::instance($moment);

        if ($this->daypart_id === null) {
            return $this->coversDay($at->startOfDay());
        }

        $daypart = $this->daypart;

        if ($daypart === null) {
            // The daypart was deleted out from under the rule. Silently playing all
            // day would be the opposite of what was asked for, so play never.
            return false;
        }

        $serviceDay = $daypart->openWindowDay($at);

        return $serviceDay !== null && $this->coversDay($serviceDay);
    }

    /**
     * Does the day-half of this rule cover one particular date?
     *
     * Public because the preview walks a week of dates through it, and because it
     * is the half worth testing on its own.
     */
    public function coversDay(CarbonInterface $moment): bool
    {
        $day = CarbonImmutable::parse(CarbonImmutable::instance($moment)->toDateString());

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
        $daypart = $this->daypart_id ? $this->daypart : null;
        $found = [];

        foreach (range(0, max(0, $days - 1)) as $offset) {
            $day = $start->addDays($offset);

            if (! $this->coversDay($day)) {
                continue;
            }

            // With no daypart the item is eligible for the whole day; with one, only
            // inside the window that day opens — and a closed weekday opens none.
            $window = $daypart?->windowFor($day->dayOfWeekIso);

            if ($daypart !== null && $window === null) {
                continue;
            }

            $found[] = [
                'date' => $day->toDateString(),
                'start' => $window[0] ?? null,
                'end' => $window[1] ?? null,
                'crosses_midnight' => $window !== null && $window[1] <= $window[0],
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
        // would put the item on a day the shop never asked for.
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
            $this->daypart_id,
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
