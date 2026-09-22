<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Store;
use App\Services\StoreTeam;

/**
 * The store a signed-in member is working in, for pages that only make sense inside one.
 */
trait ResolvesCurrentStore
{
    /**
     * 404 when there is none: no store chosen (the platform team never has one), a store that
     * was deleted, or one the person has since left or been removed from.
     */
    protected function currentStore(): Store
    {
        $storeId = (int) session('current_store_id');
        $store = $storeId > 0 ? Store::find($storeId) : null;

        abort_unless($store !== null && app(StoreTeam::class)->isMember(auth()->user(), $store), 404);

        return $store;
    }
}
