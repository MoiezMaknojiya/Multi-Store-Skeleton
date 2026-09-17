<?php

namespace App\Http\Controllers\Signage;

use App\Http\Controllers\Concerns\ConfirmsPassword;
use App\Http\Controllers\Concerns\HandlesCrudData;
use App\Http\Controllers\Concerns\ResolvesCurrentStore;
use App\Http\Controllers\Controller;
use App\Http\Requests\Signage\ChannelRequest;
use App\Models\ActivityLog;
use App\Models\Channel;
use App\Models\ChannelAd;
use App\Services\MediaStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Channels, from where the person stands (owner's rules, 2026-09-16):
 *
 *  - Above the stores (the super admin, or a global role holding the channel permissions): every
 *    channel. One made here is the platform's, offered to every shop's screens.
 *  - Inside a store (a store's role carrying the channel permissions): that store's own channels. One
 *    made here belongs to the store and is offered to its own screens alone; the platform's channels
 *    and other stores' are not found (404).
 *
 * Every lookup goes through Channel::visibleTo. Whoever holds a permission may use it on any channel
 * within reach, not only the ones they made — a channel is shared work, like a shop's media library.
 */
class ChannelController extends Controller
{
    use ConfirmsPassword, HandlesCrudData, ResolvesCurrentStore;

    public function index(Request $request): View
    {
        return view('channels.index', [
            'maxAdsPerPass' => Channel::MAX_ADS_PER_PASS,
            // The store whose own channels these are; null above the stores.
            'store' => $request->user()->globalRole() !== null ? null : $this->currentStore(),
        ]);
    }

    /** Paginated channels, each with how many ads it holds and how far it has spread. */
    public function data(Request $request): JsonResponse
    {
        $today = now()->toDateString();

        $query = Channel::query()
            ->visibleTo($request->user())
            ->with(['createdBy', 'store:id,name'])
            ->withCount([
                'ads',
                'ads as running_ads_count' => fn ($q) => $q
                    ->where(fn ($q) => $q->whereNull('starts_on')->orWhereDate('starts_on', '<=', $today))
                    ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $today)),
            ])
            ->orderBy('name');

        return $this->paginatedResponse(
            $request, $query, ['name'], 'channels', ['*'],
            fn (Collection $channels) => $this->attachUsage($channels)
        );
    }

    /** The page where one channel's ads are managed. */
    public function show(Request $request, Channel $channel): View
    {
        return view('channels.show', [
            'channel' => Channel::visibleTo($request->user())->findOrFail($channel->id),
            'maxImageSeconds' => ChannelAd::MAX_IMAGE_SECONDS,
        ]);
    }

    public function store(ChannelRequest $request): JsonResponse
    {
        // Made above the stores it is the platform's; made inside a store it is that store's own.
        $storeId = $request->user()->globalRole() !== null ? null : $this->currentStore()->id;

        $channel = Channel::create([...$request->validated(), 'store_id' => $storeId, 'created_by' => auth()->id()]);

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
     * Delete a channel, its ads and their files — and take it off every playlist that
     * carries it.
     *
     * The panel shows how many screens that is before anybody presses the button, and
     * pausing is the way to take a channel off the air without any of this.
     */
    public function destroy(Request $request, Channel $channel, MediaStorage $storage): JsonResponse
    {
        $channel = Channel::visibleTo($request->user())->findOrFail($channel->id);

        $this->confirmPassword($request);

        $name = $channel->name;
        $files = $channel->ads()->get(['id', 'disk', 'path', 'thumbnail_path']);
        $screens = $this->screensCarrying($channel);

        DB::transaction(function () use ($channel, $files, $storage) {
            // The ads, and every playlist line carrying the channel (with its schedule
            // rules), go through the foreign keys.
            $channel->delete();

            // The files only once that is committed: a delete that rolls back must not
            // leave rows pointing at files that are already gone.
            DB::afterCommit(fn () => $files->each(
                fn (ChannelAd $ad) => $storage->deleteFiles($ad->disk, $ad->path, $ad->thumbnail_path)
            ));
        });

        ActivityLog::record('channel.deleted', null,
            "Deleted channel {$name} — it was on {$screens} screen".($screens === 1 ? '' : 's'),
            storeId: $channel->store_id);

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
     * How many screens, in how many shops, carry each channel on this page.
     *
     * One query for the whole page rather than one per row. A deleted shop's screens
     * went with it, so they never count.
     *
     * @param  Collection<int, Channel>  $channels
     */
    private function attachUsage(Collection $channels): void
    {
        $usage = DB::table('playlist_items')
            ->join('screens', 'screens.id', '=', 'playlist_items.screen_id')
            ->whereIn('playlist_items.channel_id', $channels->pluck('id'))
            ->groupBy('playlist_items.channel_id')
            ->selectRaw('playlist_items.channel_id as channel_id')
            ->selectRaw('COUNT(DISTINCT playlist_items.screen_id) as screens')
            ->selectRaw('COUNT(DISTINCT screens.store_id) as stores')
            ->get()
            ->keyBy('channel_id');

        $channels->each(function (Channel $channel) use ($usage) {
            $channel->setAttribute('screens_count', (int) ($usage[$channel->id]->screens ?? 0));
            $channel->setAttribute('stores_count', (int) ($usage[$channel->id]->stores ?? 0));
            // The names only: the whole user and store rows have no business in this response.
            $channel->setAttribute('created_by_name', $channel->createdBy?->name);
            $channel->setAttribute('store_name', $channel->store?->name);
            $channel->unsetRelation('createdBy');
            $channel->unsetRelation('store');
        });
    }
}
