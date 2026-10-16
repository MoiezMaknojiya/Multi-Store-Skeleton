<?php

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every repeat that counts from a day is given that day as its Starting date (the review of 2026-10-15). "Every 2 weeks",
 * every N days or months and every year, saved with no start, counted from the row's own creation, and a playlist's save
 * writes its rules afresh — so each save moved which weeks were on, and the yearly day. From now on a save gives such a
 * repeat its day and keeps it (ScheduleRule::startsOnFor); the rules saved before are given the day they count from today
 * — the date of their creation as the resolver reads it, a UTC date — so nothing a screen plays changes.
 *
 * A Starting date is also the first day a rule may play, which the creation never was: a rule saved on a Thursday evening
 * in Chicago, already Friday in UTC, played the rest of that Thursday. So each is moved back to the start of the turn it is
 * in, which keeps the very same on weeks, months and days: the Monday of its week for every N weeks, the 1st of its month
 * for every N months, one turn earlier for every N days. A yearly repeat keeps its date, which it alone repeats on. A
 * repeat whose day would come after its own end is over — it plays nothing any more — and is left as it was: a Starting
 * date after its end would have the page's next save refused (ScheduleRule::startsOnAsPlayed sends none for it).
 *
 * Each row it changes carries one exact stamp in `updated_at` (a rule's row is only ever written whole, so it was its
 * `created_at` before), which is how down() takes back these rows alone; a rule saved since is a new row, and keeps its day.
 */
return new class extends Migration
{
    private const STAMP = '2026-10-15 21:00:00';

    private const COLUMNS = ['id', 'created_at', 'starts_on', 'ends_on', 'recurrence_type', 'recurrence_interval', 'recurrence_until'];

    public function up(): void
    {
        if (! Schema::hasTable('schedule_rules')) {
            return;
        }

        DB::table('schedule_rules')
            ->whereNull('starts_on')
            ->whereNotNull('created_at')
            ->whereNotNull('recurrence_type')
            ->where(fn (Builder $repeat) => $repeat->where('recurrence_type', 'yearly')->orWhere('recurrence_interval', '>', 1))
            ->select(self::COLUMNS)
            ->chunkById(500, function (Collection $rules): void {
                foreach ($rules as $rule) {
                    $day = $this->dayItCountsFrom($rule);

                    if ($day === null) {
                        continue;
                    }

                    DB::table('schedule_rules')->where('id', $rule->id)->update(['starts_on' => $day, 'updated_at' => self::STAMP]);
                }
            });
    }

    public function down(): void
    {
        if (! Schema::hasTable('schedule_rules')) {
            return;
        }

        DB::table('schedule_rules')
            ->where('updated_at', self::STAMP)
            ->whereNotNull('starts_on')
            ->whereNotNull('created_at')
            ->select(self::COLUMNS)
            ->chunkById(500, function (Collection $rules): void {
                foreach ($rules as $rule) {
                    // Only the day up() gave it: a Starting date typed since was a new row anyway.
                    if (substr((string) $rule->starts_on, 0, 10) !== $this->dayItCountsFrom($rule)) {
                        continue;
                    }

                    DB::table('schedule_rules')->where('id', $rule->id)->update(['starts_on' => null, 'updated_at' => $rule->created_at]);
                }
            });
    }

    /** The Starting date up() gives one row — null for a repeat already over by then. */
    private function dayItCountsFrom(object $rule): ?string
    {
        // The day the resolver counts it from: its creation's date, the time and zone left off (ScheduleRule::bareDate).
        $created = CarbonImmutable::parse(substr((string) $rule->created_at, 0, 10));
        $every = max(1, (int) $rule->recurrence_interval);

        $day = match ($rule->recurrence_type) {
            'weekly' => $created->startOfWeek(CarbonInterface::MONDAY),
            'monthly_day', 'monthly_weekday' => $created->startOfMonth(),
            'daily' => $created->subDays($every),
            default => $created,
        };

        $last = collect([$rule->ends_on, $rule->recurrence_until])
            ->filter()
            ->map(fn (string $end) => substr($end, 0, 10))
            ->min();

        return $last !== null && $last < $day->toDateString() ? null : $day->toDateString();
    }
};
