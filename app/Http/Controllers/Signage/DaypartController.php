<?php

namespace App\Http\Controllers\Signage;

use App\Http\Controllers\Concerns\HandlesCrudData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Signage\DaypartRequest;
use App\Models\ActivityLog;
use App\Models\Daypart;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DaypartController extends Controller
{
    use HandlesCrudData;

    /** Render the dayparts page. The weekday list fills the exception dropdowns, so
     *  it comes from the one list the model defines. */
    public function index(): View
    {
        return view('dayparts.index', ['weekdays' => Daypart::WEEKDAYS]);
    }

    /** Return paginated, searchable dayparts as JSON, scoped to the current store. */
    public function data(Request $request): JsonResponse
    {
        // The exceptions ride along: every row shows how many it has, and the edit
        // modal opens from the row it already holds rather than fetching again.
        $query = Daypart::visibleTo(auth()->user())->with('exceptions')->orderBy('name');

        return $this->paginatedResponse($request, $query, ['name'], 'dayparts');
    }

    /** Create a daypart together with its exceptions. */
    public function store(DaypartRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $storeId = $this->currentStoreId();

        // One transaction: a daypart whose exceptions failed to save would claim hours
        // it does not keep, which is worse than not saving at all.
        $daypart = DB::transaction(function () use ($validated, $storeId) {
            $daypart = Daypart::create([
                'store_id' => $storeId,
                'name' => $validated['name'],
                'start_time' => $validated['start_time'],
                'end_time' => $validated['end_time'],
                'is_retired' => $validated['is_retired'] ?? false,
                'created_by' => auth()->id(),
            ]);

            $daypart->syncExceptions($validated['exceptions'] ?? []);

            return $daypart;
        });

        ActivityLog::record('daypart.created', $daypart, "Created daypart {$daypart->name}");

        return response()->json([
            'message' => 'Daypart created successfully',
            'daypart' => $daypart->load('exceptions'),
        ]);
    }

    /** Rename a daypart, move its hours, retire it, or rewrite its exceptions. */
    public function update(DaypartRequest $request, Daypart $daypart): JsonResponse
    {
        // Route middleware is not enough: the target has to be inside the store the
        // actor is working in, or it does not exist for them (404, never 403).
        $daypart = Daypart::visibleTo(auth()->user())->findOrFail($daypart->id);

        $validated = $request->validated();

        DB::transaction(function () use ($daypart, $validated) {
            $daypart->update([
                'name' => $validated['name'],
                'start_time' => $validated['start_time'],
                'end_time' => $validated['end_time'],
                'is_retired' => $validated['is_retired'] ?? false,
            ]);

            $daypart->syncExceptions($validated['exceptions'] ?? []);
        });

        ActivityLog::record('daypart.updated', $daypart, "Updated daypart {$daypart->name}");

        return response()->json([
            'message' => 'Daypart updated successfully',
            'daypart' => $daypart->fresh()->load('exceptions'),
        ]);
    }

    /**
     * Delete a daypart. Its exceptions go with it through the foreign key.
     *
     * Refused while a playlist's schedule rule still points at it. The foreign key is nullOnDelete, so
     * deleting one in use would not error — it would quietly set a scheduled line playing all day, with
     * nothing to show what happened. "Retired" is the way to take a window out of circulation.
     */
    public function destroy(Daypart $daypart): JsonResponse
    {
        $daypart = Daypart::visibleTo(auth()->user())->findOrFail($daypart->id);

        if ($daypart->isInUse()) {
            throw ValidationException::withMessages([
                'name' => 'This daypart is in use by a playlist. Retire it instead — it will disappear from the pickers and keep working where it already is.',
            ]);
        }

        $name = $daypart->name;

        $daypart->delete();

        ActivityLog::record('daypart.deleted', null, "Deleted daypart {$name}", storeId: $daypart->store_id);

        return response()->json(['message' => 'Daypart deleted successfully']);
    }

    /** A daypart belongs to a store, so creating one needs a store context. */
    private function currentStoreId(): int
    {
        $storeId = (int) session('current_store_id');

        if (! $storeId) {
            throw ValidationException::withMessages([
                'name' => 'Select a store before creating a daypart — opening hours belong to the store they were set for.',
            ]);
        }

        return $storeId;
    }
}
