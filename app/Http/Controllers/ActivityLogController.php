<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesCrudData;
use App\Models\ActivityLog;
use App\Services\ActivityLogPartitioner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class ActivityLogController extends Controller
{
    use HandlesCrudData;

    /** Render the activity log page */
    public function index(): View
    {
        return view('activity.index');
    }

    /** Yearly-partition status for the storage panel (super admins on the page). */
    public function partitions(ActivityLogPartitioner $partitioner): JsonResponse
    {
        return response()->json([
            ...$partitioner->status(),
            'cutoffYear' => $partitioner->cutoffYear(now()->year),
        ]);
    }

    /** One-click yearly maintenance: opens current/next-year partitions and drops
     *  everything older than 2 years WITH its data. Destructive — super admin only. */
    public function maintainPartitions(ActivityLogPartitioner $partitioner): JsonResponse
    {
        if (! auth()->user()->isSuperAdmin()) {
            return response()->json(['message' => 'Only a Super Admin can run activity log maintenance.'], 403);
        }

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

    /** Return paginated, searchable activity data as JSON (newest first).
     *  Read-only audit trail — rows are never edited or deleted from the UI. */
    public function data(Request $request): JsonResponse
    {
        // Accepts either a full ISO-8601 instant (what the UI sends — the viewer's
        // local day boundaries converted to UTC) or a plain Y-m-d date (raw/API
        // callers). created_at is stored in UTC, so both bounds are normalized to UTC.
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $query = ActivityLog::query()->latest('id');

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
