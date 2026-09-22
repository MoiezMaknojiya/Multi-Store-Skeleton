<?php

namespace App\Http\Controllers\Signage;

use App\Http\Controllers\Concerns\HandlesCrudData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Signage\ChannelAdRequest;
use App\Models\ActivityLog;
use App\Models\Channel;
use App\Models\ChannelAd;
use App\Models\Media;
use App\Models\PlaylistItem;
use App\Services\MediaStorage;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The ads inside one channel (docs/CHANNEL-CONTENT-SPEC.md).
 *
 * An ad is a row of a media library held by id — the channel keeps no files of its own. It comes from the
 * library, from the Ad Builder (a published ad IS a library row), or from a fresh upload, which joins the
 * channel's library first: the shop's for a shop's channel, the platform's for the platform's. Taking an ad
 * out leaves its file in the library.
 *
 * Adding, editing, reordering and removing an ad are all EDITS of the channel, so all of them answer to
 * `channel-update`; deleting the channel itself is the only thing `channel-destroy` guards.
 */
class ChannelAdController extends Controller implements HasMiddleware
{
    use HandlesCrudData;

    public function __construct(private readonly MediaStorage $storage) {}

    /**
     * Every action here reaches one channel, and only a channel within reach — checked before anything else
     * runs, so an upload is not even validated against a channel out of reach. Reading its ads is open to
     * every channel the person may look at (a shop reads the platform's channels too); every change needs a
     * channel they may manage (Channel::visibleTo), and anything else is not found.
     *
     * @return array<int, Closure>
     */
    public static function middleware(): array
    {
        return [
            function (Request $request, Closure $next) {
                $channel = $request->route('channel');
                $reading = $request->isMethod('GET') && $request->route()?->getName() === 'channels.ads.index';

                $reach = $reading
                    ? Channel::listableIn($request->user())
                    : Channel::visibleTo($request->user());

                abort_unless($channel instanceof Channel && $reach->whereKey($channel->id)->exists(), 404);

                return $next($request);
            },
        ];
    }

    /** The channel's ads, in the order they play. */
    public function index(Channel $channel): JsonResponse
    {
        return response()->json(['ads' => $this->adsPayload($channel)]);
    }

    /**
     * The library rows this channel may show, for the Add-ad pickers: a shop's channel, that shop's library; the
     * platform's channel, its own library by default or a shop's (`library`). `type=html` is the Ad Builder's
     * published ads, `type=files` the pictures and videos. A permission of its own reach: it does not need
     * `media-view`, so it answers with what a tile shows and nothing more — like the playlist's picker.
     */
    public function library(Request $request, Channel $channel): JsonResponse
    {
        $filters = $request->validate([
            'type' => ['nullable', Rule::in([Media::TYPE_IMAGE, Media::TYPE_VIDEO, Media::TYPE_HTML, 'files'])],
            'library' => ['nullable', 'string', 'regex:/^(platform|[1-9][0-9]{0,9})$/'],
            'search' => ['nullable', 'string', 'max:255'],
        ]);

        // Never an Ad Builder page taken off the screens (unpublished): nobody picks one until it is published again.
        $query = Media::query()
            ->withoutDrafts()
            ->when($channel->isPlatformChannel(),
                fn (Builder $query) => ($filters['library'] ?? 'platform') === 'platform'
                    ? $query->platformOwned()
                    : $query->where('store_id', (int) $filters['library']),
                fn (Builder $query) => $query->where('store_id', $channel->store_id))
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $type === 'files'
                ? $query->whereIn('type', [Media::TYPE_IMAGE, Media::TYPE_VIDEO])
                : $query->where('type', $type))
            ->latest();

        return $this->paginatedResponse($request, $query, ['title'], 'media', ['*'],
            fn (Collection $files) => $files->transform(fn (Media $media) => [
                'id' => $media->id,
                'title' => $media->title,
                'type' => $media->type,
                'orientation' => $media->orientation,
                'duration_seconds' => $media->duration_seconds,
                'thumbnail_url' => $media->thumbnail_url,
            ]));
    }

    public function store(ChannelAdRequest $request, Channel $channel): JsonResponse
    {
        $validated = $request->validated();

        $ad = DB::transaction(function () use ($request, $validated, $channel) {
            $media = $request->hasFile('file') ? $this->uploadIntoLibrary($request, $channel) : $request->chosenMedia();

            return ChannelAd::create([
                'channel_id' => $channel->id,
                'media_id' => $media->id,
                'title' => filled($validated['title'] ?? null) ? $validated['title'] : $media->title,
                'duration_seconds' => $this->secondsFor($media, $validated),
                'starts_on' => $validated['starts_on'] ?? null,
                'ends_on' => $validated['ends_on'] ?? null,
                // A new ad joins the end of the channel.
                'position' => ((int) ChannelAd::where('channel_id', $channel->id)->max('position')) + 1,
                'created_by' => auth()->id(),
            ]);
        });

        ActivityLog::record('channel.ad_added', $channel, "Added ad {$ad->title} to channel {$channel->name}");

        return response()->json(['message' => 'Ad added', 'ads' => $this->adsPayload($channel)]);
    }

    /**
     * Retitle, re-time or re-date an ad — and optionally show another file in it. The file it showed before stays
     * in its library: it was never the channel's to delete.
     */
    public function update(ChannelAdRequest $request, Channel $channel, ChannelAd $ad): JsonResponse
    {
        $validated = $request->validated();

        DB::transaction(function () use ($request, $validated, $channel, $ad) {
            $media = match (true) {
                $request->hasFile('file') => $this->uploadIntoLibrary($request, $channel),
                filled($validated['media_id'] ?? null) => $request->chosenMedia(),
                default => $ad->media,
            };

            $ad->update([
                'media_id' => $media->id,
                'title' => filled($validated['title'] ?? null) ? $validated['title'] : $ad->title,
                'duration_seconds' => $this->secondsFor($media, $validated, $ad->duration_seconds),
                'starts_on' => $validated['starts_on'] ?? null,
                'ends_on' => $validated['ends_on'] ?? null,
            ]);
        });

        ActivityLog::record('channel.ad_updated', $channel, "Updated ad {$ad->title} in channel {$channel->name}");

        return response()->json(['message' => 'Ad updated', 'ads' => $this->adsPayload($channel)]);
    }

    /** Take an ad out of the channel. Its file stays in its library. */
    public function destroy(Channel $channel, ChannelAd $ad): JsonResponse
    {
        $title = $ad->title;

        $ad->delete();

        ActivityLog::record('channel.ad_removed', $channel, "Removed ad {$title} from channel {$channel->name}");

        return response()->json(['message' => 'Ad removed', 'ads' => $this->adsPayload($channel)]);
    }

    /**
     * Put the channel's ads in a new order.
     *
     * The whole order in one call, and exactly this channel's own ads: a partial list
     * would leave two ads sharing a place, and a foreign id would reach into somebody
     * else's channel.
     */
    public function reorder(Request $request, Channel $channel): JsonResponse
    {
        $validated = $request->validate([
            'ad_ids' => ['present', 'array'],
            'ad_ids.*' => ['integer', 'min:1', 'distinct'],
        ]);

        $posted = collect($validated['ad_ids'])->map(fn (int|string $id) => (int) $id)->values();
        $current = ChannelAd::where('channel_id', $channel->id)->pluck('id')->map(fn (int|string $id) => (int) $id);

        if ($posted->sort()->values()->all() !== $current->sort()->values()->all()) {
            throw ValidationException::withMessages([
                'ad_ids' => 'The ads in this channel have changed. Reload the page and try again.',
            ]);
        }

        DB::transaction(function () use ($posted, $channel) {
            $posted->each(fn (int $id, int $position) => ChannelAd::where('channel_id', $channel->id)
                ->whereKey($id)
                ->update(['position' => $position]));
        });

        ActivityLog::record('channel.ads_reordered', $channel, "Reordered the ads in channel {$channel->name}");

        return response()->json(['message' => 'Order saved', 'ads' => $this->adsPayload($channel)]);
    }

    /**
     * A file uploaded inside a channel joins the channel's library first — the shop's for a shop's channel, the
     * platform's for the platform's — exactly as an upload on the Media page does, and shows up there after.
     */
    private function uploadIntoLibrary(ChannelAdRequest $request, Channel $channel): Media
    {
        $media = $this->storage->addToLibrary(
            $request->file('file'),
            $channel->store_id,
            $request->only(['duration_seconds', 'width', 'height', 'poster']),
            $request->validated('title'),
            auth()->id(),
        );

        ActivityLog::record('media.uploaded', $media, "Uploaded {$media->type} {$media->title} for channel {$channel->name}"
            .($media->isPlatformOwned() ? " to the platform's library" : ''));

        return $media;
    }

    /**
     * An image or an ad page shows for the seconds it was given; a video has none — it runs to its own end.
     *
     * @param  array<string, mixed>  $validated
     */
    private function secondsFor(Media $media, array $validated, ?int $current = null): ?int
    {
        if ($media->type === Media::TYPE_VIDEO) {
            return null;
        }

        // The rules demand seconds for anything but a video, so they are always here — with a fallback anyway,
        // because the alternative to a default is a 500 on a save.
        return (int) ($validated['seconds'] ?? $current ?? PlaylistItem::DEFAULT_IMAGE_SECONDS);
    }

    /** @return array<int, array<string, mixed>> */
    private function adsPayload(Channel $channel): array
    {
        $today = now();

        return ChannelAd::where('channel_id', $channel->id)
            ->with(['media.store:id,name', 'media.builderAd'])
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (ChannelAd $ad) => [
                'id' => $ad->id,
                'media_id' => $ad->media_id,
                'title' => $ad->title,
                'type' => $ad->type,
                'orientation' => $ad->orientation,
                'url' => $ad->url,
                'thumbnail_url' => $ad->thumbnail_url,
                'duration_seconds' => $ad->duration_seconds,
                'play_seconds' => $ad->play_seconds,
                'starts_on' => $ad->starts_on?->toDateString(),
                'ends_on' => $ad->ends_on?->toDateString(),
                // Whose library the file is in — "Platform", or the shop's name.
                'library' => $ad->media?->isPlatformOwned() ? 'Platform' : $ad->media?->store?->name,
                // Against the server's own date: this list is read by one person at a
                // desk, not by a screen standing in some other timezone.
                'status' => $ad->statusOn($today),
            ])
            ->all();
    }
}
