<?php

namespace App\Http\Controllers\Signage;

use App\Http\Controllers\Concerns\ConfirmsPassword;
use App\Http\Controllers\Concerns\HandlesCrudData;
use App\Http\Controllers\Concerns\ResolvesCurrentOrganization;
use App\Http\Controllers\Controller;
use App\Http\Requests\Signage\ChannelRequest;
use App\Models\ActivityLog;
use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Channels, from where the person stands (owner's rules, 2026-09-16):
 *
 *  - Above the organizations (the super admin, or a global role holding the channel permissions): every
 *    channel. One made here is the platform's, offered to every organization's screens.
 *  - Inside an organization (an organization's role carrying the channel permissions): that organization's own channels. One
 *    made here belongs to the organization and is offered to its own screens alone. The platform's channels are
 *    listed and opened there to READ only (owner, 2026-09-19); other organizations' are not found (404).
 *
 * Every change goes through Channel::visibleTo, every look through Channel::listableIn. Whoever holds a
 * permission may use it on any channel within reach, not only the ones they made — a channel is shared
 * work, like an organization's media library.
 */
class ChannelController extends Controller
{
    use ConfirmsPassword, HandlesCrudData, ResolvesCurrentOrganization;

    public function index(Request $request): View
    {
        return view('channels.index', [
            'maxAdsPerPass' => Channel::MAX_ADS_PER_PASS,
            // The organization whose own channels these are; null above the organizations.
            'organization' => $request->user()->globalRole() !== null ? null : $this->currentOrganization(),
        ]);
    }

    /**
     * Paginated channels, each with how many ads it holds and how far it has spread. Inside an organization the list
     * also carries the platform's channels, marked read-only (owner, 2026-09-19): nothing on them may be changed
     * from there, and the server refuses it anyway.
     */
    public function data(Request $request): JsonResponse
    {
        $today = now()->toDateString();

        $query = Channel::query()
            ->listableIn($request->user())
            ->with(['createdBy', 'organization:id,name'])
            ->withCount([
                'ads',
                'ads as running_ads_count' => fn (Builder $q) => $q
                    ->where(fn (Builder $q) => $q->whereNull('starts_on')->orWhereDate('starts_on', '<=', $today))
                    ->where(fn (Builder $q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today))
                    // An Ad Builder page taken off the screens (unpublished) is off the air, whatever its dates say.
                    ->whereHas('media', fn (Builder $q) => $q->withoutDrafts()),
            ])
            ->orderBy('name');

        return $this->paginatedResponse(
            $request, $query, ['name'], 'channels', ['*'],
            fn (Collection $channels) => $this->attachUsage($channels, $request->user()->globalRole() !== null)
        );
    }

    /**
     * The page where one channel's ads are managed — or, for the platform's channel seen from inside an organization,
     * only looked at.
     */
    public function show(Request $request, Channel $channel): View
    {
        $channel = Channel::listableIn($request->user())->findOrFail($channel->id);

        return view('channels.show', [
            'channel' => $channel,
            'readOnly' => ! Channel::visibleTo($request->user())->whereKey($channel->id)->exists(),
            'maxImageSeconds' => ChannelAd::MAX_IMAGE_SECONDS,
            // Above the organizations, the platform's channel may take any organization's files: the pickers ask which library.
            'libraries' => $channel->isPlatformChannel() && $request->user()->globalRole() !== null
                ? Organization::orderBy('name')->get(['id', 'name'])->toArray()
                : [],
        ]);
    }

    public function store(ChannelRequest $request): JsonResponse
    {
        // Made above the organizations it is the platform's; made inside an organization it is that organization's own.
        $organizationId = $request->user()->globalRole() !== null ? null : $this->currentOrganization()->id;

        $channel = Channel::create([...$request->validated(), 'organization_id' => $organizationId, 'created_by' => auth()->id()]);

        ActivityLog::record('channel.created', $channel, "Created channel {$channel->name}");

        return response()->json(['message' => 'Channel created', 'channel' => $channel->fresh()]);
    }

    /** ChannelRequest has already answered 404 for a channel out of reach. */
    public function update(ChannelRequest $request, Channel $channel): JsonResponse
    {
        $channel->update($request->validated());

        ActivityLog::record('channel.updated', $channel, "Updated channel {$channel->name}");

        return response()->json(['message' => 'Channel updated', 'channel' => $channel->fresh()]);
    }

    /**
     * Delete a channel and its ads — and take it off every playlist that carries it. The ads' files belong to
     * their libraries and stay there.
     *
     * The panel shows how many screens that is before anybody presses the button, and
     * pausing is the way to take a channel off the air without any of this.
     */
    public function destroy(Request $request, Channel $channel): JsonResponse
    {
        $channel = Channel::visibleTo($request->user())->findOrFail($channel->id);

        $this->confirmPassword($request);

        $name = $channel->name;
        $screens = $this->screensCarrying($channel);

        // The ads, and every playlist line carrying the channel (with its schedule rules), go through the
        // foreign keys.
        DB::transaction(fn () => $channel->delete());

        ActivityLog::record('channel.deleted', null,
            "Deleted channel {$name} — it was on {$screens} screen".($screens === 1 ? '' : 's'),
            organizationId: $channel->organization_id);

        return response()->json(['message' => 'Channel deleted']);
    }

    /**
     * How many screens carry this channel — counted the same way the listing counts it, so the number in the
     * confirmation, the number in the table and the number in the log can never disagree by one.
     */
    private function screensCarrying(Channel $channel): int
    {
        return DB::table('playlist_items')
            ->join('screens', 'screens.id', '=', 'playlist_items.screen_id')
            ->where('playlist_items.channel_id', $channel->id)
            ->distinct()
            ->count('playlist_items.screen_id');
    }

    /**
     * How many screens, in how many organizations, carry each channel on this page — and whether the reader may change
     * it. Inside an organization only the organization's own screens are counted: how far the platform's channel has spread in
     * other organizations is not this organization's business.
     *
     * One query for the whole page rather than one per row. A deleted organization's screens
     * went with it, so they never count.
     *
     * @param  Collection<int, Channel>  $channels
     */
    private function attachUsage(Collection $channels, bool $aboveTheOrganizations): void
    {
        $usage = DB::table('playlist_items')
            ->join('screens', 'screens.id', '=', 'playlist_items.screen_id')
            ->whereIn('playlist_items.channel_id', $channels->pluck('id'))
            ->when(! $aboveTheOrganizations, fn (QueryBuilder $query) => $query->where('screens.organization_id', (int) session('current_organization_id')))
            ->groupBy('playlist_items.channel_id')
            ->selectRaw('playlist_items.channel_id as channel_id')
            ->selectRaw('COUNT(DISTINCT playlist_items.screen_id) as screens')
            ->selectRaw('COUNT(DISTINCT screens.organization_id) as organizations')
            ->get()
            ->keyBy('channel_id');

        $channels->each(function (Channel $channel) use ($usage, $aboveTheOrganizations) {
            // The platform's channel, seen from inside an organization: listed to read, never to change.
            $readOnly = ! $aboveTheOrganizations && $channel->isPlatformChannel();

            $channel->setAttribute('screens_count', (int) ($usage[$channel->id]->screens ?? 0));
            $channel->setAttribute('organizations_count', (int) ($usage[$channel->id]->organizations ?? 0));
            $channel->setAttribute('read_only', $readOnly);
            // The names only: the whole user and organization rows have no business in this response — and an organization
            // is not told who on the platform's team made the platform's channel.
            $channel->setAttribute('created_by_name', $readOnly ? null : $channel->createdBy?->name);
            $channel->setAttribute('organization_name', $channel->organization?->name);
            $channel->unsetRelation('createdBy');
            $channel->unsetRelation('organization');
        });
    }
}
