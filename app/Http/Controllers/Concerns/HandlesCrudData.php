<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The shared paginatedResponse() behind every listing in the panel — the activity log, campaigns, channels,
 * dayparts, media, permissions, screens, stores and accounts — so search, paging and the JSON shape are
 * written once.
 */
trait HandlesCrudData
{
    /** The panel's page size, kept in step with ROWS_PER_PAGE in
     *  resources/js/core/crud-table-base.js. Only reached when a caller sends no
     *  per_page — the panel always sends one. */
    private const ROWS_PER_PAGE = 50;

    /**
     * Build a paginated, searchable JSON response from an Eloquent query.
     *
     * @param  Request  $request  HTTP request with search/page/per_page parameters
     * @param  Builder  $query  Base Eloquent query (may be pre-scoped with visibility constraints)
     * @param  array  $searchColumns  Column names to apply LIKE search across
     * @param  string  $dataKey  Key name for the items array in the JSON response
     * @param  array  $selectColumns  Optional column subset to select (['*'] by default)
     * @param  \Closure|null  $batchTransform  Optional callback given the whole page's Collection at once, so
     *                                         enrichment that needs queries runs one query for the page
     *                                         instead of one per row (avoids N+1)
     * @param  \Closure|null  $searchExtra  Optional ($query, $search) callback run INSIDE the same search
     *                                      group, for anything a plain LIKE cannot express — a date typed
     *                                      the way the panel prints it, say. Use orWhere inside it, and only
     *                                      alongside at least one $searchColumns entry, so the group still
     *                                      starts with a where.
     */
    protected function paginatedResponse(
        Request $request,
        Builder $query,
        array $searchColumns,
        string $dataKey,
        array $selectColumns = ['*'],
        ?\Closure $batchTransform = null,
        ?\Closure $searchExtra = null
    ): JsonResponse {
        // A query string carries whatever a visitor writes: ?search[]=x arrives as an ARRAY, and an
        // array reaching the LIKE clause below is an "Array to string conversion" — a 500 on every
        // listing in the panel. Anything that is not one plain value is read as though nothing was
        // sent, which keeps this endpoint forgiving rather than making the panel handle a 422.
        $search = self::plainValue($request->input('search'));
        $page = max((int) self::plainValue($request->input('page'), '1'), 1);
        // Mirrors ROWS_PER_PAGE in resources/js/core/crud-table-base.js, so a client that
        // sends no per_page gets the same page every listing in the panel shows.
        // Clamped so nobody can dump an entire table with ?per_page=999999.
        $perPage = min(max((int) self::plainValue($request->input('per_page'), (string) self::ROWS_PER_PAGE), 1), 100);

        if ($search) {
            $query->where(function ($q) use ($search, $searchColumns, $searchExtra) {
                foreach ($searchColumns as $i => $col) {
                    $method = $i === 0 ? 'where' : 'orWhere';
                    $q->$method($col, 'like', "%{$search}%");
                }

                if ($searchExtra) {
                    $searchExtra($q, $search);
                }
            });
        }

        $results = $query->paginate($perPage, $selectColumns, 'page', $page);

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

    /**
     * One plain value out of a request, or the default. A parameter can arrive as an array or an
     * object (?search[]=x, ?page[a]=1) and nothing further down expects that — see the note in
     * paginatedResponse(). Use it wherever a word is read straight from a request without a
     * Form Request having checked its shape first.
     */
    protected static function plainValue(mixed $value, string $default = ''): string
    {
        return is_scalar($value) ? (string) $value : $default;
    }
}
