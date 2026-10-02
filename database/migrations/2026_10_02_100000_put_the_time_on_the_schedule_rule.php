<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The time of day is typed on the schedule rule itself, and the dayparts are gone (owner, 2026-10-01 — "dayparts ko
 * hata k … Playlist mein jab schedule set karte ha waha"; docs/SCHEDULE-SPEC.md §21).
 *
 * A rule used to point at a named daypart for its hours. It now carries them — `start_time` and `end_time`, both
 * empty for the whole day, an end before the start running past midnight as before — so every rule that named a
 * daypart is given that daypart's hours, and the two daypart tables and the four daypart permissions go.
 *
 * A daypart could keep other hours on some weekdays, or none. A rule that used one becomes one rule for each set of
 * hours, repeating on the weekdays that keep them: exactly the same hours on exactly the same days
 * (ScheduleTimesMigrationTest holds every half hour of two months to it). Two things cannot be said so, and either
 * stops this migration before anything is changed, naming the rule: a repeat that cannot also say "only on these
 * weekdays" — every N days, on a date of the month, once a year — on such a daypart; and a daypart one of whose
 * weekdays opens before the night before has closed, on a rule that tells its days apart.
 *
 * `down()` gives the tables, the column and the permissions back, with a daypart for every set of hours the rules
 * hold, named by its hours.
 */
return new class extends Migration
{
    /** What the catalogue said of dayparts, for `down()`. */
    private const PERMISSIONS = [
        'daypart-view' => 'View Dayparts',
        'daypart-store' => 'Create Dayparts',
        'daypart-update' => 'Update Dayparts',
        'daypart-destroy' => 'Delete Dayparts',
    ];

    public function up(): void
    {
        $hadDayparts = Schema::hasTable('dayparts') && Schema::hasColumn('schedule_rules', 'daypart_id');

        // Read first, and refused first: nothing below runs for a database with a rule that cannot be carried over.
        $replacements = $hadDayparts ? $this->replacements() : collect();

        if (! Schema::hasColumn('schedule_rules', 'start_time')) {
            Schema::table('schedule_rules', function (Blueprint $table) {
                // Wall-clock times, read in the SCREEN's timezone. Both null is the whole day; an end before the
                // start runs past midnight, and the two are never equal (the playlist's rules refuse it).
                $table->time('start_time')->nullable()->after('playlist_item_id');
                $table->time('end_time')->nullable()->after('start_time');
            });
        }

        // One transaction for the rows, and each rule lets go of its daypart as it is rewritten: run again after a
        // failure further down, there is nothing left to rewrite twice.
        DB::transaction(function () use ($replacements) {
            foreach ($replacements as $ruleId => $rows) {
                $this->rewrite((int) $ruleId, $rows);
            }
        });

        if (Schema::hasColumn('schedule_rules', 'daypart_id')) {
            $this->dropTheDaypartColumn();
        }

        Schema::dropIfExists('daypart_exceptions');
        Schema::dropIfExists('dayparts');

        $ids = DB::table('permissions')->whereIn('name', array_keys(self::PERMISSIONS))->pluck('id');
        DB::table('role_has_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }

    public function down(): void
    {
        if (! Schema::hasTable('dayparts')) {
            Schema::create('dayparts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->string('name', 100);
                $table->time('start_time');
                $table->time('end_time');
                $table->boolean('is_retired')->default(false);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['organization_id', 'name']);
                $table->index(['organization_id', 'is_retired']);
            });
        }

        if (! Schema::hasTable('daypart_exceptions')) {
            Schema::create('daypart_exceptions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('daypart_id')->constrained()->cascadeOnDelete();
                $table->unsignedTinyInteger('weekday');
                $table->time('start_time')->nullable();
                $table->time('end_time')->nullable();
                $table->unique(['daypart_id', 'weekday']);
            });
        }

        if (! Schema::hasColumn('schedule_rules', 'daypart_id')) {
            Schema::table('schedule_rules', function (Blueprint $table) {
                $table->foreignId('daypart_id')->nullable()->after('playlist_item_id')->constrained()->nullOnDelete();
            });
        }

        if (Schema::hasColumn('schedule_rules', 'start_time')) {
            DB::transaction(fn () => $this->nameTheHours());

            Schema::table('schedule_rules', function (Blueprint $table) {
                $table->dropColumn(['start_time', 'end_time']);
            });
        }

        $this->giveThePermissionsBack();
    }

    /**
     * What each rule that names a daypart becomes: its own row rewritten, and a row more for every further set of
     * hours its daypart keeps. Keyed by the rule's id.
     *
     * @return Collection<int, list<array<string, mixed>>>
     */
    private function replacements(): Collection
    {
        $dayparts = DB::table('dayparts')->get()->keyBy('id');
        $exceptions = DB::table('daypart_exceptions')->get()->groupBy('daypart_id');
        $rules = DB::table('schedule_rules')->whereNotNull('daypart_id')->orderBy('id')->get();
        $refused = [];
        $replacements = collect();

        foreach ($rules as $rule) {
            $daypart = $dayparts->get($rule->daypart_id);

            // A daypart id with nothing behind it played never (ScheduleRule::coversAt), and still does.
            $hours = $daypart === null ? [] : $this->hoursByWeekday($daypart, $exceptions->get($daypart->id, collect()));

            // One weekday opening before the night before has closed: the daypart gave such a moment to the day
            // that opens, and two rules would give it to both. With days to tell apart, that is not the same.
            if ($this->namesDays($rule) && $this->opensBeforeTheNightBeforeCloses($hours)) {
                $refused[] = $this->describe($rule, $daypart).' opens on one weekday before the hours of the day before have closed';

                continue;
            }

            $rows = $this->rowsFor($rule, $hours);

            if ($rows === null) {
                $refused[] = $this->describe($rule, $daypart).' keeps other hours on some weekdays, which this repeat cannot say';

                continue;
            }

            $replacements->put($rule->id, $rows);
        }

        if ($refused !== []) {
            throw new RuntimeException(
                "These schedules cannot be carried over as they stand, so nothing was changed. Change each schedule or its daypart, then migrate again:\n - "
                .implode("\n - ", $refused)
            );
        }

        return $replacements;
    }

    /** Whether the rule says anything about its days: a rule that says nothing covers every day alike. */
    private function namesDays(object $rule): bool
    {
        return $rule->recurrence_type !== null || $rule->starts_on !== null || $rule->ends_on !== null || $rule->recurrence_until !== null;
    }

    /**
     * Whether some weekday's hours begin while the hours of the weekday before, running past midnight, are still open.
     *
     * @param  array<int, string>  $hours  by weekday
     */
    private function opensBeforeTheNightBeforeCloses(array $hours): bool
    {
        foreach ($hours as $weekday => $set) {
            [$start, $end] = explode('-', $set);
            $next = $hours[$weekday % 7 + 1] ?? null;

            if ($end <= $start && $next !== null && explode('-', $next)[0] < $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * The hours a daypart keeps on each weekday it opens (1 = Monday … 7 = Sunday), as "H:i:s-H:i:s".
     *
     * @param  Collection<int, object>  $exceptions
     * @return array<int, string>
     */
    private function hoursByWeekday(object $daypart, Collection $exceptions): array
    {
        $hours = [];

        foreach (range(1, 7) as $weekday) {
            $exception = $exceptions->firstWhere('weekday', $weekday);

            if ($exception === null) {
                $hours[$weekday] = $this->clock($daypart->start_time).'-'.$this->clock($daypart->end_time);
            } elseif ($exception->start_time !== null && $exception->end_time !== null) {
                $hours[$weekday] = $this->clock($exception->start_time).'-'.$this->clock($exception->end_time);
            }
            // Both empty: closed that weekday, so it has no hours at all.
        }

        return $hours;
    }

    /**
     * The rows that say what this rule said, or null when no rows can.
     *
     * @param  array<int, string>  $hours  by weekday; a weekday left out is closed
     * @return list<array<string, mixed>>|null
     */
    private function rowsFor(object $rule, array $hours): ?array
    {
        $sets = array_values(array_unique($hours));

        // Open every day at the same hours: the rule keeps its days and takes the hours.
        if (count($hours) === 7 && count($sets) === 1) {
            return [$this->timed([], $sets[0])];
        }

        $weekdaysOf = fn (string $set) => array_keys($hours, $set, true);
        $type = $rule->recurrence_type;
        $interval = max(1, (int) $rule->recurrence_interval);

        // Every day (with or without dates): the weekdays that keep each set of hours, every week. A repeat ends
        // with `recurrence_until`, so the last day moves there — the schedule window shows that box for a repeat.
        if ($type === null || ($type === 'daily' && $interval === 1)) {
            $until = collect([$rule->ends_on, $rule->recurrence_until])->filter()->min();
            $rows = array_map(fn (string $set) => $this->timed([
                'recurrence_type' => 'weekly',
                'recurrence_interval' => 1,
                'recurrence_weekdays' => json_encode($weekdaysOf($set)),
                'ends_on' => null,
                'recurrence_until' => $until,
            ], $set), $sets);

            return $rows === [] ? [$this->never($rule)] : $rows;
        }

        // Some weekdays, every N weeks: of those weekdays, the ones that keep each set of hours.
        if ($type === 'weekly') {
            $chosen = array_map('intval', (array) json_decode((string) $rule->recurrence_weekdays, true));
            $rows = [];

            foreach ($sets as $set) {
                $days = array_values(array_intersect($chosen, $weekdaysOf($set)));

                if ($days !== []) {
                    $rows[] = $this->timed(['recurrence_weekdays' => json_encode($days)], $set);
                }
            }

            return $rows === [] ? [$this->never($rule)] : $rows;
        }

        // The third Thursday: whatever hours Thursday keeps — or never, where Thursday is closed.
        if ($type === 'monthly_weekday') {
            $set = $hours[(int) $rule->recurrence_weekday] ?? null;

            return [$set === null ? $this->never($rule) : $this->timed([], $set)];
        }

        // Every N days, the 21st of the month, once a year: which weekday that is changes each time.
        return $sets === [] ? [$this->never($rule)] : null;
    }

    /** @return array<string, mixed> */
    private function timed(array $changes, string $set): array
    {
        [$start, $end] = explode('-', $set);

        return [...$changes, 'start_time' => $start, 'end_time' => $end];
    }

    /**
     * A rule that never fired — its daypart closed on every day the rule chose — keeps never firing: every week, on
     * no weekday. With no rule at all its line would play around the clock, the opposite of what it did.
     *
     * @return array<string, mixed>
     */
    private function never(object $rule): array
    {
        return [
            'recurrence_type' => 'weekly',
            'recurrence_interval' => max(1, (int) $rule->recurrence_interval),
            'recurrence_weekdays' => json_encode([]),
            'recurrence_monthday' => null,
            'recurrence_ordinal' => null,
            'recurrence_weekday' => null,
            'start_time' => null,
            'end_time' => null,
        ];
    }

    /**
     * Write one rule's rows: the first over the rule itself, the others after it on the same line, and the line's
     * rules numbered again in the order they stand.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function rewrite(int $ruleId, array $rows): void
    {
        $rule = DB::table('schedule_rules')->where('id', $ruleId)->first();

        if ($rule === null) {
            return;
        }

        DB::table('schedule_rules')->where('id', $ruleId)->update([...array_shift($rows), 'daypart_id' => null]);

        if ($rows === []) {
            return;
        }

        $original = (array) DB::table('schedule_rules')->where('id', $ruleId)->first();
        unset($original['id']);

        // Half a place after the rule each: they stand behind it, in order, once the line is numbered again.
        $order = [$ruleId => $rule->position];

        foreach ($rows as $index => $row) {
            $order[DB::table('schedule_rules')->insertGetId([...$original, ...$row])] = $rule->position + ($index + 1) / (count($rows) + 1);
        }

        $line = DB::table('schedule_rules')->where('playlist_item_id', $rule->playlist_item_id)->orderBy('position')->orderBy('id')->get(['id', 'position']);

        $line->sortBy(fn (object $row) => [$order[$row->id] ?? $row->position, $row->id])->values()
            ->each(fn (object $row, int $position) => DB::table('schedule_rules')->where('id', $row->id)->update(['position' => $position]));
    }

    /** The key first, by the name the database gives it, then the column. */
    private function dropTheDaypartColumn(): void
    {
        $key = collect(Schema::getForeignKeys('schedule_rules'))->first(fn (array $key) => $key['columns'] === ['daypart_id']);

        if ($key !== null) {
            Schema::table('schedule_rules', function (Blueprint $table) use ($key) {
                // SQLite drops a key by its columns (it rebuilds the table); MySQL by the name it was given.
                DB::getDriverName() === 'sqlite' ? $table->dropForeign(['daypart_id']) : $table->dropForeign($key['name']);
            });
        }

        Schema::table('schedule_rules', function (Blueprint $table) {
            $table->dropColumn('daypart_id');
        });
    }

    /** `down()`: every set of hours a rule holds becomes a daypart of its organization, named by its hours. */
    private function nameTheHours(): void
    {
        $rules = DB::table('schedule_rules')
            ->join('playlist_items', 'playlist_items.id', '=', 'schedule_rules.playlist_item_id')
            ->join('screens', 'screens.id', '=', 'playlist_items.screen_id')
            ->whereNotNull('schedule_rules.start_time')
            ->whereNotNull('schedule_rules.end_time')
            ->get(['schedule_rules.id', 'schedule_rules.start_time', 'schedule_rules.end_time', 'screens.organization_id']);

        foreach ($rules as $rule) {
            $start = $this->clock($rule->start_time);
            $end = $this->clock($rule->end_time);
            $name = substr($start, 0, 5).' – '.substr($end, 0, 5);

            $daypart = DB::table('dayparts')->where('organization_id', $rule->organization_id)->where('name', $name)->value('id')
                ?? DB::table('dayparts')->insertGetId([
                    'organization_id' => $rule->organization_id,
                    'name' => $name,
                    'start_time' => $start,
                    'end_time' => $end,
                    'is_retired' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

            DB::table('schedule_rules')->where('id', $rule->id)->update(['daypart_id' => $daypart]);
        }
    }

    /** `down()`: the four permissions, held again by Super-Admin and by the starter roles that began with them. */
    private function giveThePermissionsBack(): void
    {
        foreach (self::PERMISSIONS as $name => $label) {
            if (! DB::table('permissions')->where('name', $name)->exists()) {
                DB::table('permissions')->insert(['name' => $name, 'label' => $label, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        $all = DB::table('permissions')->whereIn('name', array_keys(self::PERMISSIONS))->pluck('id', 'name');
        $everything = DB::table('roles')->where(fn (Builder $roles) => $roles->whereIn('key', ['owner', 'admin'])
            ->orWhere(fn (Builder $role) => $role->where('is_global', true)->where('name', 'Super-Admin')))->pluck('id');
        $viewOnly = DB::table('roles')->whereIn('key', ['staff', 'viewer'])->pluck('id');

        foreach ($everything as $roleId) {
            foreach ($all as $permissionId) {
                $this->grant((int) $roleId, (int) $permissionId);
            }
        }

        foreach ($viewOnly as $roleId) {
            $this->grant((int) $roleId, (int) $all['daypart-view']);
        }
    }

    private function grant(int $roleId, int $permissionId): void
    {
        if (! DB::table('role_has_permissions')->where('role_id', $roleId)->where('permission_id', $permissionId)->exists()) {
            DB::table('role_has_permissions')->insert([
                'role_id' => $roleId, 'permission_id' => $permissionId, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /** One shape for a clock time, whichever database kept it: "H:i:s". */
    private function clock(string $time): string
    {
        return substr($time, 0, 5).':00';
    }

    /** One line that names a rule this migration cannot carry over, for whoever has to change it. */
    private function describe(object $rule, ?object $daypart): string
    {
        $line = DB::table('playlist_items')->where('id', $rule->playlist_item_id)->first();
        $screen = $line === null ? null : DB::table('screens')->where('id', $line->screen_id)->value('name');
        $repeat = [
            'daily' => 'repeats every '.$rule->recurrence_interval.' days',
            'weekly' => 'repeats on some weekdays',
            'monthly_day' => 'repeats on day '.$rule->recurrence_monthday.' of the month',
            'monthly_weekday' => 'repeats on a weekday of the month',
            'yearly' => 'repeats once a year',
        ][$rule->recurrence_type] ?? 'runs between dates';

        return 'schedule #'.$rule->id.' on screen "'.($screen ?? '?').'", line '.(($line->position ?? 0) + 1).' '.$repeat.', and its daypart "'.($daypart->name ?? '?').'"';
    }
};
