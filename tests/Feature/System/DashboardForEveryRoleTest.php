<?php

use App\Models\ActivityLog;
use App\Models\Media;
use App\Models\Organization;
use App\Models\Screen;
use Illuminate\Routing\Middleware\ThrottleRequests;

/*
|--------------------------------------------------------------------------
| An organization's dashboard for every role an organization could make (owner, 2026-09-30)
|--------------------------------------------------------------------------
|
| Every combination of the permissions the dashboard reads — 2^7 roles — opened as a person holding exactly that
| one: the page opens, it offers no buttons of its own, every link it offers opens for that person, the parts shown
| are the ones the role may see, and "nothing for your role" shows only when there is nothing else at all.
|
*/

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);
});

test('every role\'s dashboard opens, offers only what the role may open and never a button of its own', function () {
    $organization = Organization::factory()->create(['name' => 'Alpha Mart']);
    Screen::factory()->create(['organization_id' => $organization->id, 'last_seen_at' => null]);
    Media::factory()->create(['organization_id' => $organization->id]);
    ActivityLog::create(['actor_name' => 'Ali', 'organization_id' => $organization->id, 'action' => 'media.uploaded', 'description' => 'Uploaded a poster']);

    $permissions = ['screen-view', 'screen-store', 'media-view', 'media-store', 'ad-view', 'channel-view', 'activity-view'];

    foreach (range(0, 2 ** count($permissions) - 1) as $mask) {
        $held = array_values(array_filter($permissions, fn (string $p, int $i) => ($mask >> $i) & 1, ARRAY_FILTER_USE_BOTH));
        $person = createOrganizationUser($organization, $held, "Role {$mask}");
        $as = fn () => $this->actingAs($person)->withSession(['current_organization_id' => $organization->id]);

        $response = $as()->get('/dashboard')->assertOk();
        $summary = $response->viewData('summary');
        $role = json_encode($held);

        expect($summary)->not->toHaveKey('actions')
            ->and($response->getContent())->not->toContain('dusk="dashboard-actions"');

        // What is shown is what the role may see.
        $cards = array_column($summary['cards'], 'key');
        expect(in_array('screens', $cards, true))->toBe(in_array('screen-view', $held, true), "screens card for {$role}")
            ->and(in_array('media', $cards, true))->toBe(in_array('media-view', $held, true), "files card for {$role}")
            ->and(in_array('ads', $cards, true))->toBe(in_array('ad-view', $held, true), "ads card for {$role}")
            ->and(in_array('channels', $cards, true))->toBe(in_array('channel-view', $held, true), "channels card for {$role}")
            ->and(is_null($summary['activity']))->toBe(! in_array('activity-view', $held, true), "log for {$role}")
            ->and(is_null($summary['attention']))->toBe(! array_intersect(['screen-view', 'media-view'], $held), "attention for {$role}");

        // "Nothing for your role" only when there is nothing else.
        $nothing = str_contains($response->getContent(), 'dusk="dashboard-organization-nothing"');
        expect($nothing)->toBe($cards === [] && $summary['activity'] === null, "the empty card for {$role}");

        // Every link offered opens for this very person.
        $links = collect([...$summary['cards'], ...$summary['steps'], ...($summary['attention'] ?? [])])->pluck('href')->filter()->unique();
        foreach ($links as $link) {
            $as()->get($link)->assertOk();
        }
    }
});
