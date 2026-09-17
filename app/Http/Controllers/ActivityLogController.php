<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesCrudData;
use App\Http\Controllers\Concerns\ResolvesCurrentStore;
use App\Models\ActivityLog;
use App\Services\ActivityLogPartitioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * The activity log, from where the person stands (owner's rules, 2026-09-16): above the stores, every
 * store's history and the platform's own; inside a store — a store's role carrying activity-view — that
 * store's entries alone (ActivityLog::record stamps the store). Yearly maintenance drops a year for every
 * store at once, so it and its storage panel stay above the stores (the routes' global-tier).
 */
class ActivityLogController extends Controller
{
    use HandlesCrudData, ResolvesCurrentStore;

    /** Render the activity log page */
    public function index(Request $request): View
    {
        return view('activity.index', [
            'store' => $request->user()->globalRole() !== null ? null : $this->currentStore(),
        ]);
    }

    /** Yearly-partition status for the storage panel (shown to whoever may run maintenance). */
    public function partitions(ActivityLogPartitioner $partitioner): JsonResponse
    {
        return response()->json([
            ...$partitioner->status(),
            'cutoffYear' => $partitioner->cutoffYear(now()->year),
        ]);
    }

    /** One-click yearly maintenance: opens this year's partition and the next two, and drops
     *  everything older than 2 years WITH its data. Destructive — so it is its own
     *  permission, activity-destroy, which only a global role can carry (the super
     *  admin holds it, and may hand it to a global user). Both locks are on the route. */
    public function maintainPartitions(ActivityLogPartitioner $partitioner): JsonResponse
    {
        $result = $partitioner->maintain(now()->year);

        $droppedYears = collect($result['dropped'])->pluck('year')->implode(', ') ?: 'none';
        $createdYears = implode(', ', $result['created']) ?: 'none';
        ActivityLog::record('activity.maintenance', null,
            "Activity log maintenance — partitions created: {$createdYears}; dropped (with data): {$droppedYears}");

        return response()->json([
            'message' => 'Maintenance complete',
            ...$result,
        ]);
    }

    /** Return paginated, searchable activity data as JSON (newest first). An entry is never edited; whole
     *  years are dropped by the yearly maintenance below, and nothing else deletes one. */
    public function data(Request $request): JsonResponse
    {
        // Accepts either a full ISO-8601 instant (what the UI sends — the viewer's local day boundaries
        // converted to UTC) or a plain Y-m-d date. created_at is stored in UTC, so both bounds are
        // normalized to UTC.
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $query = ActivityLog::query()->latest('id');

        // A store's people read their own store's history, and nothing logged before entries carried a store.
        if ($request->user()->globalRole() === null) {
            $query->where('store_id', $this->currentStore()->id);
        }

        // Bounding by created_at is what makes the yearly partitions pay off:
        // MySQL prunes to only the partitions inside the range, so a search
        // never scans years the user did not ask for.
        if (! empty($validated['from'])) {
            $query->where('created_at', '>=', $this->boundToUtc($validated['from'], startOfDay: true));
        }
        if (! empty($validated['to'])) {
            $query->where('created_at', '<=', $this->boundToUtc($validated['to'], startOfDay: false));
        }

        return $this->paginatedResponse(
            $request,
            $query,
            ['action', 'description', 'actor_name'],
            'logs'
        );
    }

    /** Normalize a from/to bound to a UTC "Y-m-d H:i:s" string for comparison
     *  against the UTC-stored created_at. A date-only value (no time part) covers
     *  the whole day; a full datetime is used as the exact instant it names. */
    private function boundToUtc(string $value, bool $startOfDay): string
    {
        $carbon = Carbon::parse($value)->utc();

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $carbon = $startOfDay ? $carbon->startOfDay() : $carbon->endOfDay();
        }

        return $carbon->format('Y-m-d H:i:s');
    }
}
