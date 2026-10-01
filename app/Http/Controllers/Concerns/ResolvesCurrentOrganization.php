<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Organization;
use App\Services\OrganizationTeam;

/**
 * The organization a signed-in member is working in, for pages that only make sense inside one.
 */
trait ResolvesCurrentOrganization
{
    /**
     * 404 when there is none: no organization chosen (the platform team never has one), an organization that
     * was deleted, or one the person has since left or been removed from.
     */
    protected function currentOrganization(): Organization
    {
        $organizationId = (int) session('current_organization_id');
        $organization = $organizationId > 0 ? Organization::find($organizationId) : null;

        abort_unless($organization !== null && app(OrganizationTeam::class)->isMember(auth()->user(), $organization), 404);

        return $organization;
    }
}
