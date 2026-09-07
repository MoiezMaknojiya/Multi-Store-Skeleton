<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Provides a shared paginatedResponse() method used by PermissionController,
 * StoreController, RoleController, and UserController to eliminate
 * duplicated search + paginate + JSON response logic.
 */
trait HandlesCrudData
{
    /**
     * Build a paginated, searchable JSON response from an Eloquent query.
     *
     * @param  Request  $request  HTTP request with search/page/per_page parameters
     * @param  Builder  $query  Base Eloquent query (may be pre-scoped with visibility constraints)
     * @param  array  $searchColumns  Column names to apply LIKE search across
     * @param  string  $dataKey  Key name for the items array in the JSON response
     * @param  array  $selectColumns  Optional column subset to select (empty = all columns)
     * @param  \Closure|null  $transform  Optional callback applied to each model before serialization
     * @param  \Closure|null  $batchTransform  Optional callback given the whole page's Collection at once —
     *                                         use this (not $transform) when enrichment needs queries, so one
     *                                         query serves the page instead of one per row (avoids N+1)
     */
    protected function paginatedResponse(
        Request $request,
        Builder $query,
        array $searchColumns,
        string $dataKey,
        array $selectColumns = ['*'],
        ?\Closure $transform = null,
        ?\Closure $batchTransform = null
    ): JsonResponse {
        $search = $request->input('search', '');
        $page = (int) $request->input('page', 1);
        // Clamped so nobody can dump an entire table with ?per_page=999999.
        $perPage = min(max((int) $request->input('per_page', 10), 1), 100);

        if ($search) {
            $query->where(function ($q) use ($search, $searchColumns) {
                foreach ($searchColumns as $i => $col) {
                    $method = $i === 0 ? 'where' : 'orWhere';
                    $q->$method($col, 'like', "%{$search}%");
                }
            });
        }

        $results = $query->paginate($perPage, $selectColumns, 'page', $page);

        if ($transform) {
            $results->getCollection()->transform($transform);
        }

        if ($batchTransform) {
            $batchTransform($results->getCollection());
        }

        return response()->json([
            $dataKey => $results->items(),
            'total' => $results->total(),
            'currentPage' => $results->currentPage(),
            'lastPage' => $results->lastPage(),
            'perPage' => $results->perPage(),
        ]);
    }
}
