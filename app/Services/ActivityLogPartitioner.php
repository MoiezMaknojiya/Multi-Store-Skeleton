<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Lifecycle of the yearly activity_logs partitions — run by the monthly schedule
 * (routes/console.php) and by the button on the Activity Log page. Retention rule
 * (owner's decision): keep the current and previous year; anything older than 2
 * years is dropped WITH its data.
 *
 * On MySQL this uses real RANGE partitions (instant DROP PARTITION / a
 * REORGANIZE of pmax to open the next year), so retention is at YEARLY granularity
 * — a whole year's partition is dropped once that year is older than the cutoff.
 * On other drivers (the SQLite test databases) the same intent is met with a plain
 * DELETE by exact date. The two therefore agree on whole years but not to-the-day:
 * on MySQL, rows inside the oldest KEPT year that predate the exact cutoff date
 * linger until their whole year ages out. The caller-facing contract (keep current
 * + previous year, open 3 years ahead) is the same on both.
 */
class ActivityLogPartitioner
{
    /** Years to keep: current + previous. Anything with year < cutoff is dropped. */
    public function cutoffYear(int $currentYear): int
    {
        return $currentYear - 1;
    }

    /** @return array{driver: string, partitions: array<int, array{name: string, year: int|null, rows: int}>} */
    public function status(): array
    {
        if (DB::getDriverName() === 'mysql') {
            $rows = DB::select(
                'SELECT PARTITION_NAME AS name FROM information_schema.PARTITIONS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND PARTITION_NAME IS NOT NULL
                 ORDER BY PARTITION_ORDINAL_POSITION',
                ['activity_logs']
            );

            $partitions = array_map(function ($row) {
                $year = preg_match('/^p(\d{4})$/', $row->name, $m) ? (int) $m[1] : null;
                $count = $year !== null
                    ? DB::table('activity_logs')->whereYear('created_at', $year)->count()
                    : DB::table('activity_logs')->where('created_at', '>=', $this->yearStart($this->maxNamedYear() + 1))->count();

                return ['name' => $row->name, 'year' => $year, 'rows' => $count];
            }, $rows);

            return ['driver' => 'mysql', 'partitions' => $partitions];
        }

        // Fallback drivers: report the years present in the data.
        $years = DB::table('activity_logs')
            ->selectRaw("strftime('%Y', created_at) as y, count(*) as c")
            ->groupBy('y')->orderBy('y')->get();

        return [
            'driver' => DB::getDriverName(),
            'partitions' => $years->map(fn ($r) => ['name' => 'p'.$r->y, 'year' => (int) $r->y, 'rows' => (int) $r->c])->all(),
        ];
    }

    /**
     * Create partitions for the next 3 years (current + two ahead) when missing
     * and drop everything older than the cutoff (data included).
     *
     * @return array{created: array<int, int>, dropped: array<int, array{year: int, rows: int}>}
     */
    public function maintain(int $currentYear): array
    {
        $cutoff = $this->cutoffYear($currentYear);

        if (DB::getDriverName() !== 'mysql') {
            // Same outcome without partitions: delete the old years.
            $old = DB::table('activity_logs')
                ->selectRaw("strftime('%Y', created_at) as y, count(*) as c")
                ->where('created_at', '<', $this->yearStart($cutoff))
                ->groupBy('y')->get();

            DB::table('activity_logs')->where('created_at', '<', $this->yearStart($cutoff))->delete();

            return [
                'created' => [],
                'dropped' => $old->map(fn ($r) => ['year' => (int) $r->y, 'rows' => (int) $r->c])->all(),
            ];
        }

        $existing = collect($this->status()['partitions']);
        $namedYears = $existing->pluck('year')->filter()->all();

        // 1. FIRST drop everything older than the cutoff — partition AND data,
        //    instantly. Doing this first lightens the table before any rebuild work.
        $dropped = [];
        foreach ($existing as $partition) {
            if ($partition['year'] !== null && $partition['year'] < $cutoff) {
                DB::statement("ALTER TABLE activity_logs DROP PARTITION {$partition['name']}");
                $dropped[] = ['year' => $partition['year'], 'rows' => $partition['rows']];
            }
        }

        // 2. THEN open partitions 3 years ahead (current + next two) if missing
        //    (rows that accumulated in pmax get re-homed by the REORGANIZE —
        //    cheaper now that the old years are already gone).
        $created = [];
        $toCreate = array_values(array_filter(
            [$currentYear, $currentYear + 1, $currentYear + 2],
            fn ($y) => ! in_array($y, $namedYears, true)
        ));
        if ($toCreate !== []) {
            sort($toCreate);
            $defs = implode(', ', array_map(fn ($y) => "PARTITION p{$y} VALUES LESS THAN (".($y + 1).')', $toCreate));
            DB::statement("ALTER TABLE activity_logs REORGANIZE PARTITION pmax INTO ({$defs}, PARTITION pmax VALUES LESS THAN MAXVALUE)");
            $created = $toCreate;
        }

        return ['created' => $created, 'dropped' => $dropped];
    }

    private function yearStart(int $year): string
    {
        return $year.'-01-01 00:00:00';
    }

    private function maxNamedYear(): int
    {
        $years = DB::select(
            "SELECT PARTITION_NAME AS name FROM information_schema.PARTITIONS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity_logs' AND PARTITION_NAME REGEXP '^p[0-9]{4}$'"
        );

        return collect($years)->map(fn ($r) => (int) substr($r->name, 1))->max() ?? now()->year;
    }
}
